<?php

namespace App\Jobs;

use App\Enums\NotificationKind;
use App\Mail\WorkerInvitationMail;
use App\Models\WorkerInvitation;
use App\Services\NotificationLogService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * F-26 招待メールを 1 通送るジョブ（docs/01 の非同期の契約、async-rules。Rails の ActiveJob + deliver_now に相当）。
 * 型は SendApplicationConfirmedMail と同じ（mails キュー・流量制限・同じ通知を重ねない・例外 3 回か期限で打ち切り・failed() で記録）。
 *
 * - コンストラクタは招待の ID だけ。招待コードは渡さない（ペイロードに秘密を入れない）。コードは送る直前に code 列（暗号化）から読む
 * - 実行時に読み直し、使えない招待（使用済み・取り消し・期限切れ）と、無効になったワーカーには送らない
 * - メールはこのジョブの中で Mail::to()->send() で送る。WorkerInvitationMail は ShouldQueue にしない（キューにコードが載らないように）
 * - 企業は積んだときの Context（CurrentCompany）がワーカーで戻る（ほかのメールのジョブと同じ）。auth() は使わない。
 *   招待はグローバルスコープを通して引く（スコープは外さない）。Context が戻っていなければ LogicException になる（閉じた側に倒れる）。
 *   企業を取り違えたジョブは、他社の招待がスコープで見えないので送らない
 * - ログ・例外・通知の記録（error 列）に招待コードを出さない（error は NotificationLogService が固定の文言にする）
 * https://readouble.com/laravel/13.x/ja/queues.html
 */
class SendWorkerInvitationMail implements ShouldQueue
{
    use Queueable;

    /**
     * 試行の回数では打ち切らない（0 = 無制限）。打ち切りは「例外 3 回」（$maxExceptions）か「積んでから一定時間」（retryUntil()）。
     * 流量制限（RateLimited）でキューへ戻された回を失敗に数えないため（理由は SendApplicationConfirmedMail と同じ）
     * https://readouble.com/laravel/13.x/ja/queues.html#max-job-attempts-and-timeout
     */
    public int $tries = 0;

    /** 送信などで例外が 3 回出たら失敗にする（キューへ戻された回は数えない） */
    public int $maxExceptions = 3;

    public function __construct(public readonly int $invitationId)
    {
        $this->onQueue('mails');
    }

    /**
     * 積んでから config('myteam.mail_retry_until_minutes') 分（既定 60 分）を過ぎたら、それ以上は試さず failed() に入る
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('myteam.mail_retry_until_minutes'));
    }

    /**
     * 1 回目の再試行は 10 秒後、2 回目は 60 秒後
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * - RateLimited('mails'): メールを送るジョブはすべて同じリミッター（メールサーバー全体の流量）。ロックより先に置く
     * - WithoutOverlapping: 同じ招待のメールを同時に 2 つ走らせない（キーは dedupe_key）。重なった 2 本目は戻さずに捨て、
     *   ワーカーが落ちてロックが残っても 120 秒で外れる。招待の ID は全社の通し番号なのでキーに企業ID を入れない
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
        $invitation = $this->target();
        if ($invitation === null) {
            return;
        }

        $log = $logs->claim(NotificationKind::WorkerInvitation, $invitation->worker, $invitation);
        if ($log === null) {
            return; // 送信済み（再試行・二重投入・手動の再実行）
        }

        Mail::to($log->recipient_email)->send(new WorkerInvitationMail($invitation));
        $logs->markSent($log);
    }

    /**
     * 例外 3 回（maxExceptions）か期限切れ（retryUntil）で打ち切った後に 1 回だけ呼ばれる。
     * claim 済みならその行を failed にし、行がなければ、まだ送るべき招待のときだけ「届いていない」記録を作る。
     * 記録の失敗は report() するだけにして、failed() から例外を漏らさない（SendApplicationConfirmedMail と同じ）
     */
    public function failed(?Throwable $e): void
    {
        try {
            $logs = app(NotificationLogService::class);
            if ($logs->markFailedByKey($this->dedupeKey(), $e)) {
                return;
            }

            $invitation = $this->target();
            if ($invitation !== null) {
                $logs->recordFailure(NotificationKind::WorkerInvitation, $invitation->worker, $invitation, $e);
            }
        } catch (Throwable $recordError) {
            report($recordError);
        }
    }

    /**
     * 送るべき招待（宛先のワーカーと企業コードの企業を読み込み済み）。送らないなら null。handle() と failed() で同じ判定を使う。
     * 消えた・他社（スコープで見えない）・使えない（使用済み・取り消し・期限切れ。WorkerInvitation::isUsable()）の招待と、
     * 無効になったワーカーには送らない（無効のワーカーは初回登録できないので、届いても使えない）
     */
    private function target(): ?WorkerInvitation
    {
        $invitation = WorkerInvitation::with('worker', 'company')->find($this->invitationId);

        if ($invitation === null || ! $invitation->isUsable() || ! $invitation->worker->is_active) {
            return null;
        }

        return $invitation;
    }

    /**
     * worker_invitation:worker_invitation:{招待ID}。DB は読まない（failed() は招待が消えていても呼ばれる）
     */
    private function dedupeKey(): string
    {
        $subject = new WorkerInvitation;
        $subject->setAttribute($subject->getKeyName(), $this->invitationId);

        return NotificationLogService::dedupeKey(NotificationKind::WorkerInvitation, $subject);
    }
}
