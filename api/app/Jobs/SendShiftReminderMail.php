<?php

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationKind;
use App\Mail\ShiftReminderMail;
use App\Models\Application;
use App\Services\NotificationLogService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * F-22 前日リマインドを 1 通送るジョブ（async-rules）。型は SendPostingCancelledMail と同じ。
 *
 * app:send-reminders コマンドが、対象日に勤務がある確定済みの応募 1 件ごとに 1 つ積む。
 * 「2 回送らない」の 3 段のうち、このジョブが受け持つのは 2 段目と 3 段目:
 * - ShouldBeUnique（uniqueId = 応募ID）: コマンドを 2 回実行しても、同じ応募のジョブを 2 つ積まない（積む前にキャッシュのロック）
 * - notification_logs.dedupe_key の UNIQUE（claim）: 送信済みの後に再実行・再投入されても 2 通目を送らない
 * - 同時に 2 本走ること自体は WithoutOverlapping で止める（UNIQUE が防ぐのは「送信済みの後」だけ）
 *
 * - コンストラクタは応募ID と対象日（"Y-m-d"）だけ。実行時に読み直し、まだ確定で勤務日が対象日のときだけ送る
 *   （積んだ後にキャンセルされた・募集の勤務日が変わった応募に「明日です」と送らない）
 * - 企業は積んだときの Context（コマンドの CurrentCompany::runAs()）がワーカーで戻る。auth() は使わない
 * https://readouble.com/laravel/13.x/ja/queues.html#unique-jobs
 */
class SendShiftReminderMail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * 試行の回数では打ち切らない（0 = 無制限）。打ち切りは「例外 3 回」（$maxExceptions）か「積んでから一定時間」（retryUntil()）。
     *
     * 試行回数はキューから取り出すたびに増え、流量制限（RateLimited）でキューへ戻された回も数える。回数で打ち切ると、
     * 戻されるだけで 1 通も送ろうとしないまま failed になる。retryUntil() があれば Laravel は $tries を見ずに期限だけで判定するので、
     * それを防いでいるのは retryUntil()。$tries = 0 は retryUntil() を外したときに回数で打ち切らないための保険（async-rules）。
     * https://readouble.com/laravel/13.x/ja/queues.html#max-job-attempts-and-timeout
     */
    public int $tries = 0;

    /** 送信などで例外が 3 回出たら失敗にする（キューへ戻された回は数えない） */
    public int $maxExceptions = 3;

    /**
     * @param  string  $workDate  リマインドする勤務日（"Y-m-d"）。コマンドの対象日（既定は翌日、--date で指定）
     */
    public function __construct(public readonly int $applicationId, public readonly string $workDate)
    {
        $this->onQueue('mails');
    }

    /**
     * 重複防止のロックのキー。応募ID は全社の通し番号なので企業ID は不要（ロックのキーにはクラス名も入る）
     */
    public function uniqueId(): string
    {
        return (string) $this->applicationId;
    }

    /**
     * 重複防止のロックを持つ秒数。ジョブが終われば（成功でも failed でも）その場で外れるので、これは
     * 「ワーカーが落ちてジョブが消えた」ときにロックが残り続けないための上限。再試行の期限（retryUntil）と同じにする
     * （それを過ぎたジョブは failed になるので、同じ応募のジョブを積み直せてよい）
     */
    public function uniqueFor(): int
    {
        return (int) config('myteam.mail_retry_until_minutes') * 60;
    }

    /**
     * 積んでから config('myteam.mail_retry_until_minutes') 分（既定 60 分）を過ぎたら、それ以上は試さず failed() に入る
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('myteam.mail_retry_until_minutes'));
    }

    /**
     * 1 回目の再試行は 10 秒後、2 回目は 60 秒後（すぐ再試行しても同じ理由で失敗するため）
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * - RateLimited('mails'): 18 時に全社分を一度に積んでも、1 分あたりの送信数を抑える（全社・全種類のメールで共通の枠）。
     *   ロックを取る前に判定するので、先に置く
     * - WithoutOverlapping: 同じ通知のジョブを同時に 2 つ走らせない（dontRelease で 2 本目は捨てる、expireAfter で落ちたワーカーのロックを外す）。
     *   ShouldBeUnique があっても要るのは、それをすり抜けて同じ応募のジョブが 2 本になる経路があるため:
     *   queue:retry（failed_jobs から戻すときは積む前のロックを取らない）と、uniqueFor が切れた後の再投入。
     *   notification_logs の UNIQUE は「送信済みの後」しか防がないので、同時に走る 2 本はここで止める
     * https://readouble.com/laravel/13.x/ja/queues.html#job-middleware
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('mails'),
            (new WithoutOverlapping($this->dedupeKey()))->dontRelease()->expireAfter(120),
        ];
    }

    public function handle(NotificationLogService $logs): void
    {
        $application = $this->target();
        if ($application === null) {
            return;
        }

        $log = $logs->claim(NotificationKind::ShiftReminder, $application->worker, $application);
        if ($log === null) {
            return; // 送信済み（再試行・二重投入・コマンドの再実行）
        }

        Mail::to($log->recipient_email)->send(new ShiftReminderMail($application));
        $logs->markSent($log);
    }

    /**
     * 例外 3 回（maxExceptions）か期限切れ（retryUntil）で打ち切った後に 1 回だけ呼ばれる（SendPostingCancelledMail と同じ）:
     * - claim の後に失敗していれば、その行を failed にする
     * - 行がなければ、まだ送るべき対象のときだけ recordFailure() で failed の行を作る（「届いていない」を見えるように）
     * failed() から例外を漏らさない（記録の失敗は report() してログに残すだけ）
     */
    public function failed(?Throwable $e): void
    {
        try {
            $logs = app(NotificationLogService::class);
            if ($logs->markFailedByKey($this->dedupeKey(), $e)) {
                return;
            }

            $application = $this->target();
            if ($application !== null) {
                $logs->recordFailure(NotificationKind::ShiftReminder, $application->worker, $application, $e);
            }
        } catch (Throwable $recordError) {
            report($recordError);
        }
    }

    /**
     * 送るべき応募（宛先と表示に要る関連を読み込み済み）。送らないなら null。handle() と failed() で同じ判定を使う。
     * 消えた・他社（スコープで見えない）・確定ではない・勤務日が対象日ではない応募には送らない。
     * 勤務日がすでに過ぎていても送らない（日をまたいでから queue:retry した・ワーカーが止まっていた、などで過去の勤務に
     * 「当日はよろしく」が届くのを防ぐ）。当日はまだ役に立つので送る。"Y-m-d" 同士なので文字列で比べられる
     */
    private function target(): ?Application
    {
        $application = Application::with('worker', 'jobPosting.location')->find($this->applicationId);

        if ($application === null || $application->status !== ApplicationStatus::Confirmed
            || $application->jobPosting->work_date->toDateString() !== $this->workDate
            || $this->workDate < today()->toDateString()) {
            return null;
        }

        return $application;
    }

    /**
     * shift_reminder:application:{応募ID}。ID だけを持つ未保存のインスタンスを渡す（DB は読まない）
     */
    private function dedupeKey(): string
    {
        $subject = new Application;
        $subject->setAttribute($subject->getKeyName(), $this->applicationId);

        return NotificationLogService::dedupeKey(NotificationKind::ShiftReminder, $subject);
    }
}
