<?php

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Enums\NotificationKind;
use App\Mail\PostingCancelledMail;
use App\Models\Application;
use App\Services\NotificationLogService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * F-21 中止通知を 1 通送るジョブ（async-rules）。型は SendApplicationConfirmedMail と同じ。
 *
 * 募集を中止すると、確定 → 企業都合キャンセルになった応募 1 件ごとに 1 ジョブ積まれる（1 ジョブで全員に送らない）。
 * 1 人への送信が失敗しても、他の人のジョブは別に動くので届く。失敗した 1 つだけが backoff で再試行され、
 * 例外 3 回（maxExceptions）か積んでから一定時間（retryUntil）であきらめたら failed() で notification_logs を failed にする（failed_jobs から queue:retry で再実行できる）。
 *
 * - コンストラクタは応募ID だけ。実行時に読み直し、中止による企業都合キャンセルでなければ送らない
 * - 企業は積んだときの Context（CurrentCompany）がワーカーで戻る。auth() は使わない
 * https://readouble.com/laravel/13.x/ja/queues.html
 */
class SendPostingCancelledMail implements ShouldQueue
{
    use Queueable;

    /**
     * 試行の回数では打ち切らない（0 = 無制限）。打ち切りは「例外 3 回」（$maxExceptions）か「積んでから一定時間」（retryUntil()）。
     *
     * $tries = 3 だと、流量制限（RateLimited）でキューへ戻された回も試行に数えられ、上限を超える分が続くと
     * 1 通も送ろうとしないまま failed になる。しかも claim の前なので notification_logs に行が残らず、
     * 「届いていない」が画面から見えない（要件の非機能要件に反する。テストで再現した）。メールのジョブは
     * tries=0 + maxExceptions + retryUntil にそろえる（async-rules）。
     * https://readouble.com/laravel/13.x/ja/queues.html#max-exceptions
     */
    public int $tries = 0;

    /** 送信などで例外が 3 回出たら失敗にする（キューへ戻された回は数えない） */
    public int $maxExceptions = 3;

    public function __construct(public readonly int $applicationId)
    {
        $this->onQueue('mails');
    }

    /**
     * 積んでから config('myteam.mail_retry_until_minutes') 分（既定 60 分）を過ぎたら、それ以上は試さず failed() に入る。
     * 積んだときに計算されてペイロードに入る（queue:retry で再実行すると、その時点から数え直される）
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
     * - RateLimited('mails'): 定員の多い募集を中止して一度に大量に積んでも、1 分あたりの送信数を抑える
     *   （流量はメールサーバー全体の制約なので、メールのジョブはすべて同じリミッター。上限は AppServiceProvider の
     *   RateLimiter::for('mails')。超えた分は送らずにキューへ戻す）。
     *   ロックを取る前に判定するので、先に置く
     * - WithoutOverlapping: 同じ通知のジョブを同時に 2 つ走らせない（SendApplicationConfirmedMail と同じ。dontRelease と expireAfter）
     *   キーに企業ID を入れないのは、応募ID が全社の通し番号で企業をまたいでも重ならないため（dedupe_key と同じ）
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

        $log = $logs->claim(NotificationKind::PostingCancelled, $application->worker, $application);
        if ($log === null) {
            return; // 送信済み（再試行・二重投入・手動の再実行）
        }

        Mail::to($log->recipient_email)->send(new PostingCancelledMail($application));
        $logs->markSent($log);
    }

    /**
     * 例外 3 回（maxExceptions）か期限切れ（retryUntil）で打ち切った後に 1 回だけ呼ばれる。業務の記録を failed にする:
     * - claim の後に失敗していれば、その行を failed にする
     * - 行がない（claim の前に打ち切られた。例: 流量制限で戻され続けて期限切れ）なら、まだ送るべき対象のときだけ
     *   recordFailure() で failed の行を作る（「届いていない」を画面から見えるように。要件の非機能要件）。対象でなければ作らない
     * failed() から例外が漏れるとワーカーの失敗処理が途中で止まるので、記録の失敗は report() してログに残すだけにする。
     * 生の例外は failed_jobs とログに残る
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
                $logs->recordFailure(NotificationKind::PostingCancelled, $application->worker, $application, $e);
            }
        } catch (Throwable $recordError) {
            report($recordError);
        }
    }

    /**
     * 送るべき応募（宛先と表示に要る関連を読み込み済み）。送らないなら null。handle() と failed() で同じ判定を使う。
     * 消えた・他社（スコープで見えない）・中止による企業都合キャンセルではない応募には送らない。
     * 応募 1 件の企業都合キャンセル（F-13）と区別するため、募集が中止であることも確かめる
     */
    private function target(): ?Application
    {
        $application = Application::with('worker', 'jobPosting.location')->find($this->applicationId);

        if ($application === null || $application->status !== ApplicationStatus::Cancelled
            || $application->cancel_reason !== CancelReason::Company
            || $application->jobPosting->status !== JobPostingStatus::Cancelled) {
            return null;
        }

        return $application;
    }

    /**
     * posting_cancelled:application:{応募ID}。対象は募集ではなく応募（宛先のワーカーごとに 1 通。docs/01 の非同期用のテーブル）。
     * ID だけを持つ未保存のインスタンスを渡す（DB は読まない。failed() は応募が消えていても呼ばれる）
     */
    private function dedupeKey(): string
    {
        $subject = new Application;
        $subject->setAttribute($subject->getKeyName(), $this->applicationId);

        return NotificationLogService::dedupeKey(NotificationKind::PostingCancelled, $subject);
    }
}
