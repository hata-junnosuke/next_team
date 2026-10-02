<?php

namespace App\Services;

use App\Enums\ReportExportStatus;
use App\Exceptions\DomainException;
use App\Jobs\BuildLocationCsvChunk;
use App\Jobs\FinalizeReportExport;
use App\Models\Company;
use App\Models\ReportExport;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * F-23 月次集計の非同期出力（docs/01 4.5・6.9・8 章、docs/03 3.4）。
 *
 * 流れ: request()（queued で作る）→ Bus::batch（拠点ごとの BuildLocationCsvChunk）
 *   → before: markProcessing()（processing + batch_id）
 *   → 各チャンク: buildChunk()（exports/{企業}/{出力}/chunks/{拠点}.csv）
 *   → then: FinalizeReportExport → finalize()（連結 → completed）→ 依頼した管理者に ReportExportReady
 *   → catch / ジョブの failed(): markFailed()（failed）。sync キューでは request() の catch が markFailed()（dispatch() のコメント）
 *
 * ジョブには業務の判断を書かず、このサービスを呼ぶだけにする（async-rules）。
 * ファイルは Storage::disk('local')（storage/app/private。Web から直接は見えない）。パスは企業 ID と出力 ID から作り、
 * 入力（month 以外のリクエストの値）からは組み立てない。ダウンロードは DB の file_path だけを使う。
 * https://readouble.com/laravel/13.x/ja/queues.html#job-batching
 */
class ReportExportService
{
    public function __construct(private readonly MonthlyReportService $reports) {}

    /**
     * 出力を受け付ける（202 で返す行）。同じ月の出力が生成中（queued / processing）なら DomainException（422）。
     *
     * 0. 締め切り（config myteam.report_export_stale_minutes）より古い queued / processing は止まったものとして failed にし、生成中に数えない
     * 1. 「生成中がないか」の確認と作成を 1 つのトランザクションにする（IMMEDIATE で開始時に書き込みロックを取るので、
     *    同時の 2 つの依頼は直列になり、後の方は先の方の行を見て 422 になる。docs/01 12 章）
     * 2. コミットした後に積む（ロールバックされた行のジョブを積まない。ワーカーが行を読めないまま走らない）
     * 3. 拠点が 0 件の月は空のバッチを作らず、FinalizeReportExport だけを積む（ヘッダだけの CSV で queued → completed。docs/03 3.4）
     *
     * 積む処理が例外になったら（Redis に繋がらない / sync キューでジョブが失敗した）、その場で failed にして行を返す。
     * 「受け付けたが失敗した」ことを画面の一覧で見せ、受付のまま残して同じ月の依頼を 422 で塞がないため。例外は report() でログに残す
     */
    public function request(User $user, string $month): ReportExport
    {
        [$export, $staleExports] = DB::transaction(function () use ($user, $month) {
            // 同じ企業の依頼を直列にする印。SQLite は lockForUpdate() を無視するが、IMMEDIATE がトランザクションの開始で
            // 書き込みロックを取るので直列になる。MySQL に移したときは、この行ロックで「確認 → 作成」の間に別の依頼が割り込めない
            // （report_exports の行は、まだないものはロックできないので、親の企業の行をロックする。docs/01 12 章）
            Company::query()->lockForUpdate()->findOrFail(CurrentCompany::id());

            $inProgress = ReportExport::query()
                ->where('month', $month)
                ->whereIn('status', [ReportExportStatus::Queued, ReportExportStatus::Processing])
                ->get();

            // 締め切り（既定 30 分）より古い生成中は、ワーカーが落ちた・ジョブが消えたなどで止まったものとみなし、
            // 生成中に数えずに failed にする。そうしないと、その月はいつまでも 422 で塞がり、作り直す手段がない
            $deadline = now()->subMinutes((int) config('myteam.report_export_stale_minutes'));
            [$stale, $active] = $inProgress->partition(fn (ReportExport $e) => $e->created_at->lessThan($deadline));

            if ($active->isNotEmpty()) {
                throw new DomainException('同じ月の出力が生成中です。');
            }

            foreach ($stale as $old) {
                $old->transitionTo(ReportExportStatus::Failed, ['failed_at' => now()]);
            }

            return [ReportExport::query()->create(['user_id' => $user->id, 'month' => $month]), $stale];
        });

        // 止まっていた出力の途中のファイルを消し、バッチが残っていればキャンセルする（コミットの後で。ファイルとバッチの操作はトランザクションの外）。
        // キャンセルすると、まだキューに残っているチャンクは先頭の cancelled() の確認で何もせずに終わる
        foreach ($staleExports as $old) {
            Storage::disk('local')->deleteDirectory(self::directory($old));

            // キャンセルに失敗しても新しい依頼は受け付け済みなので、ログに残すだけにする（古い出力は failed にしてある）
            try {
                if ($old->batch_id !== null) {
                    Bus::findBatch($old->batch_id)?->cancel();
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        try {
            $this->dispatch($export);
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($export->id);
        }

        return $export->refresh();
    }

    /**
     * 「いつ processing にするか」（sync キューと Redis で矛盾させない）:
     *
     * - dispatch() の後に processing にする案は使えない。sync キューでは dispatch() の中でチャンク → then →
     *   FinalizeReportExport まで走り、戻ってきた時点で completed になっているので、completed → processing の遷移で落ちる
     * - dispatch() の前は batch_id がまだない（job_batches の行は dispatch() の中で作られる）
     * - そこで PendingBatch の before コールバックを使う。before は「job_batches に行を作った直後・ジョブをキューへ入れる前」に、
     *   dispatch() を呼んだプロセスの中で 1 回だけ動く。sync でも Redis でも「batch_id が決まる → processing → チャンクが走る」の順になる
     * - before の例外は Laravel が report() して握りつぶす（バッチは続く）。processing になれなくても、
     *   then は queued → completed、catch は queued → failed に遷移できる（ReportExportStatus の遷移表）ので止まらない
     *
     * コールバックはシリアライズされて job_batches.options に入るので、static クロージャにして $this を掴まず、出力 ID だけを use する。
     * 動く場所はコールバックごとに違う:
     * - before: 依頼のプロセス（このリクエストの中）。Context はミドルウェアが入れた企業
     * - then / catch: ワーカー（最後に成功したチャンク / 最初に失敗したチャンクの処理中）。Context はそのジョブのものが戻っている
     * - sync キューでは、チャンク・then・catch がすべて Batch::add() のトランザクション（バッチ全体で 1 つ）の中で動く。
     *   チャンクが失敗すると sync が例外を投げ直してそのトランザクションごと巻き戻るので、catch で書いた failed も消える。
     *   sync で failed にするのは request() の catch（Redis では catch と各ジョブの failed()）
     */
    private function dispatch(ReportExport $export): void
    {
        $exportId = $export->id;
        $locationIds = $this->reports->locationIds($export->month);

        if ($locationIds === []) {
            FinalizeReportExport::dispatch($exportId);

            return;
        }

        Bus::batch(array_map(fn (int $locationId) => new BuildLocationCsvChunk($exportId, $locationId), $locationIds))
            ->name("report-export:{$exportId}")
            ->onQueue('default')
            ->before(static fn (Batch $batch) => app(self::class)->markProcessing($exportId, $batch->id))
            ->then(static fn (Batch $batch) => FinalizeReportExport::dispatch($exportId))
            ->catch(static fn (Batch $batch, ?Throwable $e) => app(self::class)->markFailed($exportId))
            ->dispatch();
    }

    /**
     * queued → processing（batch_id を残す）。queued でなければ何もしない（遅れて呼ばれた before で終端の状態を戻さない）
     */
    public function markProcessing(int $exportId, string $batchId): void
    {
        DB::transaction(function () use ($exportId, $batchId) {
            $export = ReportExport::query()->find($exportId);

            if ($export?->status === ReportExportStatus::Queued) {
                $export->transitionTo(ReportExportStatus::Processing, ['batch_id' => $batchId]);
            }
        });
    }

    /**
     * 拠点 1 つ分の CSV（ヘッダなし）を chunks/{拠点}.csv に書く。行は MonthlyReportService::csvRows()（CSV インジェクション対策済み）。
     * 同じチャンクを再試行しても上書きするだけなので、行は二重にならない
     */
    public function buildChunk(int $exportId, int $locationId): void
    {
        $export = ReportExport::query()->findOrFail($exportId);

        $stream = self::tempStream();
        foreach ($this->reports->csvRows($export->month, $locationId) as $row) {
            fputcsv($stream, $row, escape: '');
        }

        $path = self::chunkPath($export, $locationId);
        self::writeStream($path, $stream);

        // 書いている間に出力が終端になっていたら（他のチャンクの失敗で failed / 締め切りで failed）、自分のファイルを消す。
        // markFailed() がディレクトリを消した後に書き終えたチャンクが残り続けないように
        if (! $export->refresh()->status->isInProgress()) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * チャンクを拠点 ID の順に連結して monthly-{month}.csv（BOM + ヘッダ + 行）を作り、completed にする。
     * 戻り値は「今 completed か」（通知を送ってよいか）。
     *
     * - もう completed なら何もしない（then の二重実行・手動の再実行。ファイルを作り直さない）→ true
     * - failed なら何もしない（チャンクの失敗を記録した後に遅れて走った）→ false
     * - 読み書きに失敗したら例外（ジョブの再試行 → 打ち切りで failed() → markFailed()）
     *
     * 連結は 1 行ずつ読み書きし、全件をメモリに載せない。ファイルの書き込みはトランザクションの外で行い、
     * 状態の更新（transitionTo）だけを短いトランザクションにする（docs/03 7 章）
     */
    public function finalize(int $exportId): bool
    {
        $export = ReportExport::query()->findOrFail($exportId);

        if ($export->status === ReportExportStatus::Completed) {
            return true;
        }
        if (! $export->status->isInProgress()) {
            return false;
        }

        $disk = Storage::disk('local');
        $stream = self::tempStream();
        fwrite($stream, "\xEF\xBB\xBF"); // Excel が UTF-8 と判定できるよう BOM を付ける（F-18 の同期 CSV と同じ）
        fputcsv($stream, MonthlyReportService::CSV_HEADER, escape: '');

        $rowCount = 0;
        foreach (self::chunkPaths($export) as $chunkPath) {
            $chunk = $disk->readStream($chunkPath);
            if ($chunk === null) {
                throw new RuntimeException("{$chunkPath} を読めませんでした。");
            }

            while (($row = fgetcsv($chunk, escape: '')) !== false) {
                fputcsv($stream, $row, escape: '');
                $rowCount++;
            }
            fclose($chunk);
        }

        $path = self::filePath($export);
        self::writeStream($path, $stream);

        // 連結している間に failed になっていたら（チャンクの失敗の記録・締め切りで failed）、完了にしない。
        // 読み直しと遷移を 1 つのトランザクションにする（IMMEDIATE で直列。transitionTo() の LogicException をエラーログに出さない）
        $completed = DB::transaction(function () use ($export, $path, $rowCount) {
            if (! $export->refresh()->status->isInProgress()) {
                return false;
            }

            $export->transitionTo(ReportExportStatus::Completed, [
                'file_path' => $path,
                'row_count' => $rowCount,
                'completed_at' => now(),
            ]);

            return true;
        });

        if (! $completed) {
            $disk->deleteDirectory(self::directory($export)); // 自分が書いた成果物とチャンクを残さない

            return false;
        }

        $disk->deleteDirectory(self::directory($export).'/chunks');

        return true;
    }

    /**
     * queued / processing → failed。もう終端（completed / failed）なら何もしない（catch・各ジョブの failed()・受付の失敗の
     * どこから呼ばれても 1 回だけ failed になる）。途中まで書いたファイルは消す（失敗した出力はダウンロードさせない）
     */
    public function markFailed(int $exportId): void
    {
        $export = DB::transaction(function () use ($exportId) {
            $export = ReportExport::query()->find($exportId);

            if ($export === null || ! $export->status->isInProgress()) {
                return null;
            }

            $export->transitionTo(ReportExportStatus::Failed, ['failed_at' => now()]);

            return $export;
        });

        if ($export !== null) {
            Storage::disk('local')->deleteDirectory(self::directory($export));
        }
    }

    /**
     * 署名付き URL のダウンロードで渡すファイルの、local ディスク上のパス。完了していない・ファイルがないなら 404。
     *
     * ログインも Context（CurrentCompany）もないので、グローバルスコープを外して出力 ID で読む。
     * withoutGlobalScopes() を使ってよいのはここだけ: 署名は ReportExportResource が「自社の出力を読めたとき」にだけ作り、
     * 対象の ID は署名の中に入っているので、他社の ID に書き換えた URL は signed ミドルウェアが 403 にする（署名が企業を守る）。
     * このメソッドを呼んでよいのは signed を通ったダウンロードだけ。ファイルは DB の file_path だけを使い、入力からパスを組み立てない
     */
    public function downloadablePath(int $exportId): string
    {
        $export = ReportExport::query()->withoutGlobalScopes()->findOrFail($exportId);

        if ($export->status !== ReportExportStatus::Completed || $export->file_path === null || ! Storage::disk('local')->exists($export->file_path)) {
            throw new NotFoundHttpException;
        }

        return $export->file_path;
    }

    /**
     * 一覧・詳細の読み出し。job_batches を left join して進捗（total_jobs / pending_jobs）を 1 回のクエリで取る（N+1 にしない）。
     * job_batches には company_id がないが、join の条件が自社の行（グローバルスコープ）の batch_id なので他社のバッチは付かない
     *
     * @return Builder<ReportExport>
     */
    public function query(): Builder
    {
        return ReportExport::query()
            ->leftJoin('job_batches', fn (JoinClause $join) => $join->on('job_batches.id', '=', 'report_exports.batch_id'))
            ->select('report_exports.*', 'job_batches.total_jobs as batch_total_jobs', 'job_batches.pending_jobs as batch_pending_jobs');
    }

    /**
     * 連結するチャンク（拠点 ID の昇順）。then はバッチの全ジョブが成功した後にだけ呼ばれるので、chunks/ にあるのは
     * 受付時に積んだ拠点の分だけ。受付の後に勤怠が増減しても、バッチで作った分だけを連結する（連結の時点で拠点を数え直さない）
     *
     * @return list<string>
     */
    private static function chunkPaths(ReportExport $export): array
    {
        $paths = Storage::disk('local')->files(self::directory($export).'/chunks');
        usort($paths, fn (string $a, string $b) => (int) basename($a, '.csv') <=> (int) basename($b, '.csv'));

        return $paths;
    }

    private static function directory(ReportExport $export): string
    {
        return "exports/{$export->company_id}/{$export->id}";
    }

    private static function chunkPath(ReportExport $export, int $locationId): string
    {
        return self::directory($export)."/chunks/{$locationId}.csv";
    }

    private static function filePath(ReportExport $export): string
    {
        return self::directory($export)."/monthly-{$export->month}.csv";
    }

    /**
     * @return resource
     */
    private static function tempStream()
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('一時ファイルを開けませんでした。');
        }

        return $stream;
    }

    /**
     * @param  resource  $stream
     */
    private static function writeStream(string $path, $stream): void
    {
        rewind($stream);
        $written = Storage::disk('local')->writeStream($path, $stream);
        fclose($stream);

        if ($written === false) {
            throw new RuntimeException("{$path} を書き込めませんでした。");
        }
    }
}
