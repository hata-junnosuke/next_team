<?php

namespace App\Jobs;

use App\Enums\NotificationKind;
use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use App\Notifications\ReportExportReady;
use App\Services\NotificationLogService;
use App\Services\ReportExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * F-23 の仕上げ: チャンクを連結して completed にし、依頼した管理者に出力完了を知らせる。
 * バッチの then から積まれる。拠点が 0 件の月は ReportExportService::request() が直接積む。
 *
 * 通知は claim → 送信 → markSent（async-rules。export_ready:report_export:{出力ID} で 1 通だけ）。
 * 送信に失敗して再試行しても、出力はもう completed なので連結はやり直さず、通知だけを送り直す。
 *
 * async-rules のメールのジョブの型の例外: メールの流量制限（RateLimited('mails')）は付けない。管理者が自分で依頼した 1 件ごとの 1 通で、
 * 戻されると連結の再試行の回数（$tries）を食うため。tries=3 のまま例外の回数で打ち切る
 * https://readouble.com/laravel/13.x/ja/queues.html
 */
class FinalizeReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $reportExportId)
    {
        $this->onQueue('default');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * 1 回の実行の上限（秒）。ロックの期限（expireAfter 60）より短くし、ロックが外れた後に前の実行がまだ動いている状態を作らない。
     * 想定は 1 社 1 か月で数千行（拠点数十 × 勤務数百）の連結で、1 行ずつの読み書きでも数秒で終わるので 50 秒は十分な余裕がある
     */
    public int $timeout = 50;

    /**
     * 同じ出力の仕上げを同時に 2 つ走らせない（then の二重実行・手動の再実行）。ロックは仕上げ全体（連結 + 通知）にかける。
     *
     * 重なった 2 本目は dontRelease() で戻さず捨てる（1 本目が仕上げる）。releaseAfter() で戻す案は採らない:
     * 戻した回も $tries に数えるので、1 本目が遅いと 2 本目が tries を使い切って failed() に入り、
     * 出力は completed なのに通知の記録を failed にしてしまう（SendApplicationConfirmedMail の WithoutOverlapping に dontRelease() を付けているのと同じ理由）。
     *
     * 捨てた 2 本目の代わりに、1 本目が落ちたときの再実行はキューの retry_after（Redis 90 秒）が担う。
     * ロックの期限は 60 秒にして retry_after より短くし、再実行の時点でロックが外れているようにする
     * （期限が retry_after より長いと、落ちた 1 本目の再実行がロックに当たって捨てられ、出力が processing のまま残る）。
     * 出力 ID は全社の通し番号なので企業 ID は要らない
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("report-export:{$this->reportExportId}"))->dontRelease()->expireAfter(60)];
    }

    public function handle(ReportExportService $service, NotificationLogService $logs): void
    {
        if (! $service->finalize($this->reportExportId)) {
            return; // 失敗を記録済みの出力は仕上げない・知らせない
        }

        $export = ReportExport::query()->with('user')->findOrFail($this->reportExportId);

        $log = $logs->claim(NotificationKind::ExportReady, $export->user, $export);
        if ($log === null) {
            return; // 送信済み（再試行・二重実行）
        }

        $export->user->notify(new ReportExportReady($export->id, $export->month, (int) $export->row_count));
        $logs->markSent($log);
    }

    /**
     * 打ち切った後に 1 回だけ呼ばれる:
     * - まだ生成中なら（連結に失敗した）出力を failed にする
     * - もう completed なら（通知の送信に失敗した）通知の記録を failed にする。行がなければ作る（「届いていない」を残す）
     * failed() から例外を漏らさない
     */
    public function failed(?Throwable $e): void
    {
        try {
            $service = app(ReportExportService::class);
            $service->markFailed($this->reportExportId);

            $export = ReportExport::query()->with('user')->find($this->reportExportId);
            if ($export?->status !== ReportExportStatus::Completed) {
                return;
            }

            $logs = app(NotificationLogService::class);
            if (! $logs->markFailedByKey(NotificationLogService::dedupeKey(NotificationKind::ExportReady, $export), $e)) {
                $logs->recordFailure(NotificationKind::ExportReady, $export->user, $export, $e);
            }
        } catch (Throwable $recordError) {
            report($recordError);
        }
    }
}
