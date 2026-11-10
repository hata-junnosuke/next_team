<?php

namespace Tests\Feature;

use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Events\WorkerInvited;
use App\Exceptions\DomainException;
use App\Jobs\SendWorkerInvitationMail;
use App\Mail\WorkerInvitationMail;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerInvitation;
use App\Services\NotificationLogService;
use App\Services\WorkerInvitationService;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * F-26・F-27 招待の発行（docs/01 の 3.2・4.5・非同期の契約、async-rules）。
 *
 * - 発行（WorkerInvitationService::issue()）は直接呼ぶ。HTTP の入口（POST /api/workers/{worker}/invitation）は別のタスク
 * - 「積まれたか」は Queue::fake() で確かめる。「実行したらどうなるか」（メールの送信）は handle() を直接呼んで確かめる（Mail::fake()）
 */
class WorkerInvitationTest extends TestCase
{
    private function service(): WorkerInvitationService
    {
        return app(WorkerInvitationService::class);
    }

    // ---- 発行 ----

    public function test_発行したコードは紛らわしい文字を含まない8文字でハッシュと暗号化で保存される(): void
    {
        Queue::fake();
        $this->freezeSecond(); // datetime 列は秒までなので、比べる値も秒にそろえる
        $worker = Worker::factory()->create();

        $invitation = $this->service()->issue($worker);

        $code = $invitation->fresh()->code;
        $this->assertMatchesRegularExpression('/\A['.WorkerInvitation::CODE_CHARACTERS.']{8}\z/', $code);
        $this->assertSame(8, WorkerInvitation::CODE_LENGTH);
        foreach (['0', 'O', '1', 'I', 'L'] as $confusing) {
            $this->assertStringNotContainsString($confusing, WorkerInvitation::CODE_CHARACTERS);
        }

        // 引くためのハッシュ（HMAC）と、メールに書くための暗号化した列でだけ持つ（平文で保存しない）
        $row = DB::table('worker_invitations')->where('id', $invitation->id)->first();
        $this->assertSame(WorkerInvitation::hashCode($code), $row->code_hash);
        $this->assertNotSame($code, $row->code);
        $this->assertStringNotContainsString($code, (string) $row->code);
        $this->assertSame($code, decrypt($row->code, false));

        $this->assertSame($worker->id, $invitation->worker_id);
        $this->assertSame($this->company->id, $invitation->company_id);
        $this->assertTrue($invitation->expires_at->equalTo(now()->addDays((int) config('myteam.worker_invitation_days'))));
        $this->assertNull($invitation->used_at);
        $this->assertNull($invitation->revoked_at);
        $this->assertTrue($invitation->fresh()->isUsable());
    }

    // ---- 積まれたか（Queue::fake） ----

    public function test_発行すると招待のジョブが招待のidだけを持って積まれる(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create();

        $invitation = $this->service()->issue($worker);
        $code = $invitation->fresh()->code;

        $this->assertContains(ShouldDispatchAfterCommit::class, class_implements(WorkerInvited::class));
        Queue::assertPushedOn('mails', SendWorkerInvitationMail::class, function (SendWorkerInvitationMail $job) use ($invitation, $code) {
            // ペイロード（シリアライズしたジョブ）に招待コードが入っていない
            return $job->invitationId === $invitation->id
                && ! str_contains(serialize($job), $code);
        });
        Queue::assertCount(1);
    }

    public function test_トランザクションが巻き戻ると招待のジョブは積まれない(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create();

        // 発行そのものは成功したが、外側のトランザクション（ワーカーの登録など）が巻き戻った
        DB::beginTransaction();
        $this->service()->issue($worker);
        DB::rollBack();

        Queue::assertNothingPushed();
        $this->assertSame(0, WorkerInvitation::query()->count());
    }

    // ---- 送り直し ----

    public function test_送り直すと前の招待は取り消されコードが消える(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create();

        $first = $this->service()->issue($worker);
        $second = $this->service()->issue($worker);

        $first->refresh();
        $this->assertNotNull($first->revoked_at);
        $this->assertNull($first->code);
        $this->assertNull(DB::table('worker_invitations')->where('id', $first->id)->value('code'));
        $this->assertFalse($first->isUsable());

        $second->refresh();
        $this->assertNull($second->revoked_at);
        $this->assertNotNull($second->code);
        $this->assertTrue($second->isUsable());
        $this->assertNotSame($first->code_hash, $second->code_hash);

        // 使えるのは新しい招待だけ。ジョブは発行ごとに 1 つ
        $this->assertSame([$second->id], $worker->invitations()->get()->filter->isUsable()->pluck('id')->all());
        Queue::assertPushed(SendWorkerInvitationMail::class, 2);
    }

    // ---- 発行できない ----

    public function test_無効のワーカーには発行できない(): void
    {
        Queue::fake();
        $worker = Worker::factory()->inactive()->create();

        try {
            $this->service()->issue($worker);
            $this->fail('DomainException が投げられていない');
        } catch (DomainException $e) {
            $this->assertSame('無効のワーカーには招待を送れません。', $e->getMessage());
        }

        $this->assertSame(0, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_登録済みのワーカーには発行できない(): void
    {
        Queue::fake();
        $worker = Worker::factory()->registered()->create();

        try {
            $this->service()->issue($worker);
            $this->fail('DomainException が投げられていない');
        } catch (DomainException $e) {
            $this->assertSame('このワーカーはすでに初回登録を済ませています。', $e->getMessage());
        }

        $this->assertSame(0, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    // ---- 取り消しの範囲 ----

    public function test_期限切れで未使用の前の招待も取り消されコードが消える(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create();
        $expired = WorkerInvitation::factory()->expired()->create(['worker_id' => $worker->id]);

        $this->service()->issue($worker);

        $expired->refresh();
        $this->assertNotNull($expired->revoked_at);
        $this->assertNull(DB::table('worker_invitations')->where('id', $expired->id)->value('code'));
    }

    public function test_使用済みと取り消し済みの前の招待には触らない(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $worker = Worker::factory()->create();
        $usedAt = now()->subDays(2);
        $revokedAt = now()->subDay();
        $used = WorkerInvitation::factory()->used()->create(['worker_id' => $worker->id, 'used_at' => $usedAt]);
        $revoked = WorkerInvitation::factory()->revoked()->create(['worker_id' => $worker->id, 'revoked_at' => $revokedAt]);
        $this->travel(1)->hours();

        $this->service()->issue($worker);

        $used->refresh();
        $this->assertNull($used->revoked_at);
        $this->assertTrue($used->used_at->equalTo($usedAt));
        $revoked->refresh();
        $this->assertTrue($revoked->revoked_at->equalTo($revokedAt));
        $this->assertNull($revoked->used_at);
    }

    // ---- 他社・ロックして読み直した後の判定 ----

    public function test_他社のワーカーには発行できない(): void
    {
        Queue::fake();
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Worker::factory()->create());

        try {
            $this->service()->issue($theirs);
            $this->fail('ModelNotFoundException が投げられていない');
        } catch (ModelNotFoundException) {
            // 自社のスコープで読み直すので見つからない
        }

        $this->assertSame(0, DB::table('worker_invitations')->count());
        Queue::assertNothingPushed();
    }

    public function test_読み込んだ後に無効になったワーカーには発行できない(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create();
        // 渡すモデルは古いまま（is_active = true）。DB だけ無効にする（直前に別の管理者が無効化した）
        DB::table('workers')->where('id', $worker->id)->update(['is_active' => false]);
        $this->assertTrue($worker->is_active);

        try {
            $this->service()->issue($worker);
            $this->fail('DomainException が投げられていない');
        } catch (DomainException $e) {
            $this->assertSame('無効のワーカーには招待を送れません。', $e->getMessage());
        }

        $this->assertSame(0, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_読み込んだ後に初回登録を済ませたワーカーには発行できず前の招待も残る(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create();
        $previous = WorkerInvitation::factory()->create(['worker_id' => $worker->id]);
        // 渡すモデルは古いまま（registered_at = null）。DB だけ登録済みにする（直前に初回登録された）
        DB::table('workers')->where('id', $worker->id)->update(['registered_at' => now()]);
        $this->assertNull($worker->registered_at);

        try {
            $this->service()->issue($worker);
            $this->fail('DomainException が投げられていない');
        } catch (DomainException $e) {
            $this->assertSame('このワーカーはすでに初回登録を済ませています。', $e->getMessage());
        }

        $this->assertSame(1, WorkerInvitation::query()->count());
        $this->assertNull($previous->fresh()->revoked_at);
        Queue::assertNothingPushed();
    }

    // ---- イベント ----

    public function test_イベントはコミットの後に1回だけ発火し招待のidだけを持つ(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create();
        /** @var list<WorkerInvited> $fired */
        $fired = [];
        Event::listen(WorkerInvited::class, function (WorkerInvited $event) use (&$fired) {
            $fired[] = $event;
        });

        // 外側のトランザクション（WorkerService::create() の形）の中ではまだ発火しない
        $invitation = DB::transaction(function () use ($worker, &$fired) {
            $invitation = $this->service()->issue($worker);
            $this->assertSame([], $fired);
            Queue::assertNothingPushed();

            return $invitation;
        });

        $this->assertCount(1, $fired);
        $this->assertSame($invitation->id, $fired[0]->invitationId);
        // イベントの公開プロパティは招待の ID だけで、シリアライズしてもコードが現れない
        $this->assertSame(['invitationId' => $invitation->id], get_object_vars($fired[0]));
        $this->assertStringNotContainsString($invitation->fresh()->code, serialize($fired[0]));
        Queue::assertPushed(SendWorkerInvitationMail::class, 1);
    }

    // ---- コードの重なり ----

    /**
     * 次の $times 回の INSERT の code_hash を、他社の招待の code_hash に差し替える（DB の UNIQUE で本当に重ならせる）。
     * random_int() は差し替えられないので、Eloquent の creating イベントで重なりを作る。何回 INSERT を試したかを返す
     *
     * @return \ArrayObject<string, int>
     */
    private function collideNextInserts(int $times): \ArrayObject
    {
        $theirHash = CurrentCompany::runAs(Company::factory()->create()->id, fn () => WorkerInvitation::factory()->create()->code_hash);
        $counter = new \ArrayObject(['attempts' => 0]);
        WorkerInvitation::creating(function (WorkerInvitation $invitation) use ($counter, $theirHash, $times) {
            $counter['attempts']++;
            if ($counter['attempts'] <= $times) {
                $invitation->code_hash = $theirHash;
            }
        });

        return $counter;
    }

    public function test_コードが他の招待と重なったら作り直して発行できる(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create();
        $previous = WorkerInvitation::factory()->create(['worker_id' => $worker->id]);
        $counter = $this->collideNextInserts(2);

        $invitation = $this->service()->issue($worker);

        $this->assertSame(3, $counter['attempts']);
        $invitation->refresh();
        $this->assertSame(WorkerInvitation::hashCode($invitation->code), $invitation->code_hash);
        $this->assertTrue($invitation->isUsable());
        // 重なった INSERT だけが巻き戻り（セーブポイント）、同じトランザクションの取り消しは残る
        $this->assertNotNull($previous->fresh()->revoked_at);
        $this->assertSame(1, WorkerInvitation::query()->where('worker_id', $worker->id)->whereNull('revoked_at')->count());
        Queue::assertPushed(SendWorkerInvitationMail::class, 1);
    }

    public function test_コードが5回続けて重なったら諦めて全体が巻き戻る(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create();
        $previous = WorkerInvitation::factory()->create(['worker_id' => $worker->id]);
        $counter = $this->collideNextInserts(PHP_INT_MAX);

        try {
            $this->service()->issue($worker);
            $this->fail('UniqueConstraintViolationException が投げられていない');
        } catch (UniqueConstraintViolationException) {
            // 決めた回数で諦める
        }

        $this->assertSame(5, $counter['attempts']);
        $this->assertNull($previous->fresh()->revoked_at);
        $this->assertNotNull($previous->fresh()->code);
        $this->assertSame(1, WorkerInvitation::query()->where('worker_id', $worker->id)->count());
        Queue::assertNothingPushed();
    }

    // ---- 実行したらどうなるか（SendWorkerInvitationMail の handle() を直接呼ぶ） ----

    public function test_ジョブは招待コードと有効期限と企業コードを書いたメールを送り通知の記録が送信済みになる(): void
    {
        Mail::fake();
        // 企業名はファクトリの乱数（& や ' を含むことがある）なので、期待値に使うため明示する
        $this->company->update(['name' => '山田商事']);
        $invitation = $this->usableInvitation();

        $this->runJob($invitation->id);

        $companyCode = $this->company->fresh()->code;
        $this->assertNotNull($companyCode);
        Mail::assertSent(WorkerInvitationMail::class, 1);
        Mail::assertSent(WorkerInvitationMail::class, function (WorkerInvitationMail $mail) use ($companyCode) {
            $html = $mail->render();

            return count($mail->to) === 1 && $mail->hasTo('hanako@example.com')
                && $mail->cc === [] && $mail->bcc === []
                && str_contains($html, '山田花子 様')
                && str_contains($html, 'ABCD2345')
                && str_contains($html, '2036年10月10日（金） 18:30')
                && str_contains($html, $companyCode)
                && str_contains($html, '山田商事')
                // 管理画面の URL を外部に配らない（ほかのワーカー宛てのメールと同じ）
                && ! str_contains($html, '<a ');
        });

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationKind::WorkerInvitation, $log->kind);
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertNotNull($log->sent_at);
        $this->assertSame('hanako@example.com', $log->recipient_email);
        $this->assertSame('worker', $log->recipient_type);
        $this->assertSame($invitation->worker_id, $log->recipient_id);
        $this->assertSame('worker_invitation', $log->subject_type);
        $this->assertSame($invitation->id, $log->subject_id);
        $this->assertSame("worker_invitation:worker_invitation:{$invitation->id}", $log->dedupe_key);
    }

    public function test_ジョブを2回実行してもメールは1通(): void
    {
        Mail::fake();
        $invitation = $this->usableInvitation();

        $this->runJob($invitation->id);
        $this->runJob($invitation->id);

        Mail::assertSent(WorkerInvitationMail::class, 1);
        $this->assertSame(NotificationStatus::Sent, NotificationLog::query()->sole()->status);
    }

    public function test_送信に失敗するとfailedで記録が失敗になり再実行で送信済みになる(): void
    {
        $invitation = $this->usableInvitation();
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused (code=ABCD2345)'));

        try {
            $this->runJob($invitation->id);
            $this->fail('送信の例外が握りつぶされた（再試行されなくなる）');
        } catch (TransportException $e) {
            // 例外 3 回か期限切れで打ち切られた後に、ワーカーが failed() を 1 回呼ぶ
            (new SendWorkerInvitationMail($invitation->id))->failed($e);
        }

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertSame('TransportException: メールの送信に失敗しました。', $log->error);
        $this->assertStringNotContainsString('ABCD2345', (string) $log->error); // 画面に出る列に招待コードを残さない

        // メールサーバーが直った → 再実行（queue:retry と同じく、もう一度 handle() が呼ばれる）
        $this->stopFailingMailer();
        Mail::fake();
        $this->runJob($invitation->id);

        Mail::assertSent(WorkerInvitationMail::class, 1);
        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertNotNull($log->sent_at);
    }

    public function test_使用済みと取り消しと期限切れの招待には送らない(): void
    {
        Mail::fake();
        $used = WorkerInvitation::factory()->used()->create();
        $revoked = WorkerInvitation::factory()->revoked()->create();
        $expired = WorkerInvitation::factory()->expired()->create();

        foreach ([$used, $revoked, $expired] as $invitation) {
            $this->runJob($invitation->id);
            // 打ち切られた後の failed() でも「届いていない」記録を作らない（送るべき通知がない）
            (new SendWorkerInvitationMail($invitation->id))->failed(new \RuntimeException('boom'));
        }
        $this->runJob(999_999); // 見つからない招待

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_無効になったワーカーには送らない(): void
    {
        Mail::fake();
        $invitation = $this->usableInvitation();
        $invitation->worker->update(['is_active' => false]);

        $this->runJob($invitation->id);
        (new SendWorkerInvitationMail($invitation->id))->failed(new \RuntimeException('boom'));

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_招待のメールはキューに積む形にしない(): void
    {
        // Mailable を ShouldQueue にすると、Mail::to()->send() でもキューに積まれ、ペイロードに招待コードが入る
        $this->assertNotContains(ShouldQueue::class, class_implements(WorkerInvitationMail::class));

        Mail::fake();
        $invitation = $this->usableInvitation();
        $this->runJob($invitation->id);

        Mail::assertSent(WorkerInvitationMail::class, 1);
        Mail::assertNothingQueued();
    }

    // ---- ジョブの形（async-rules の型） ----

    public function test_招待のジョブはmailsキューで例外3回か積んでから60分で打ち切りbackoffは10秒と60秒(): void
    {
        $this->freezeSecond();
        $job = new SendWorkerInvitationMail(7);

        $this->assertSame('mails', $job->queue);
        $this->assertSame(0, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertEquals(now()->addMinutes(60), $job->retryUntil());
        $this->assertSame([10, 60], $job->backoff());

        $middleware = $job->middleware();
        $this->assertCount(2, $middleware);
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[1]);
        $this->assertSame('worker_invitation:worker_invitation:7', $middleware[1]->key);
        $this->assertNull($middleware[1]->releaseAfter);
        $this->assertSame(120, $middleware[1]->expiresAfter);
    }

    public function test_claimの前に打ち切られてもまだ使える招待ならfailedが届いていない記録を作る(): void
    {
        // 例: 流量制限で戻され続けて retryUntil を過ぎた（handle() に 1 度も入っていない）
        $invitation = $this->usableInvitation();

        (new SendWorkerInvitationMail($invitation->id))->failed(new \RuntimeException('期限切れ'));

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertSame('hanako@example.com', $log->recipient_email);
    }

    public function test_他社の招待のジョブは送らない(): void
    {
        Mail::fake();
        $otherId = CurrentCompany::runAs(Company::factory()->create()->id, fn () => WorkerInvitation::factory()->create()->id);

        // 企業を取り違えたジョブ（自社の Context で他社の招待ID）。グローバルスコープで見えないので送らない
        $this->runJob($otherId);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::withoutGlobalScopes()->count()); // 全社の行を数えるため（他社の行も作られていないこと）
    }

    // ---- 実行したらどうなるか ----

    public function test_送信で例外が出ると記録はpendingのまま例外を投げ直し再試行に回る(): void
    {
        $invitation = $this->usableInvitation();
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused'));

        try {
            $this->runJob($invitation->id);
            $this->fail('送信の例外が握りつぶされた（再試行されなくなる）');
        } catch (TransportException) {
            // 例外がワーカーまで届くので、maxExceptions と backoff に従って再試行される
        }

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Pending, $log->status);
        $this->assertNull($log->sent_at);
    }

    public function test_送信済みの後にfailedが呼ばれても失敗に戻さない(): void
    {
        Mail::fake();
        $invitation = $this->usableInvitation();
        $this->runJob($invitation->id);

        (new SendWorkerInvitationMail($invitation->id))->failed(new TransportException('late'));

        $this->assertSame(NotificationStatus::Sent, NotificationLog::query()->sole()->status);
    }

    public function test_送信に失敗した後に有効期限が切れたら再実行しても送らず記録は失敗のまま(): void
    {
        $invitation = $this->usableInvitation();
        $this->failOnce($invitation->id);

        // 期限（2036-10-10 18:30）を過ぎてから queue:retry された
        $this->travelTo('2036-10-10 18:31:00');
        Mail::fake();
        $this->runJob($invitation->id);

        Mail::assertNothingSent();
        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertNull($log->sent_at);
    }

    public function test_送信に失敗した後にワーカーが初回登録を済ませたら再実行しても送らない(): void
    {
        $invitation = $this->usableInvitation();
        $this->failOnce($invitation->id);

        // 初回登録（WorkerAuthService::register）と同じ状態にする: 招待は使用済みでコードが消え、ワーカーは登録済み
        $invitation->forceFill(['used_at' => now(), 'code' => null])->save();
        $invitation->worker->forceFill(['password' => 'secret-pass', 'registered_at' => now()])->save();
        Mail::fake();
        $this->runJob($invitation->id);

        Mail::assertNothingSent();
        $this->assertSame(NotificationStatus::Failed, NotificationLog::query()->sole()->status);
    }

    public function test_積んだ後に送り直された前の招待のジョブは送らず新しい招待のジョブだけが送る(): void
    {
        Mail::fake();
        $worker = Worker::factory()->create(['name' => '山田花子', 'email' => 'hanako@example.com', 'is_active' => true]);
        Queue::fake(); // 発行で積まれたジョブはここでは走らせず、下で handle() を直接呼ぶ
        $first = $this->service()->issue($worker);
        $second = $this->service()->issue($worker); // 送り直し（前の招待は取り消されコードが消える）
        $newCode = $second->fresh()->code;

        $this->runJob($first->id);
        $this->runJob($second->id);

        Mail::assertSent(WorkerInvitationMail::class, 1);
        Mail::assertSent(WorkerInvitationMail::class, fn (WorkerInvitationMail $mail) => str_contains($mail->render(), $newCode));
        $log = NotificationLog::query()->sole();
        $this->assertSame($second->id, $log->subject_id);
    }

    public function test_ワーカー名のmarkdown記号はリンクにならない(): void
    {
        Mail::fake();
        $invitation = $this->usableInvitation();
        $invitation->worker->update(['name' => '[花子](https://evil.example)']);

        $this->runJob($invitation->id);

        Mail::assertSent(WorkerInvitationMail::class, function (WorkerInvitationMail $mail) {
            $html = $mail->render();

            return ! str_contains($html, '<a ')
                && ! str_contains($html, 'href="https://evil.example"')
                && str_contains($html, 'evil.example');
        });
    }

    public function test_ワーカー名の改行は空白になり見出しや段落を作らない(): void
    {
        Mail::fake();
        $invitation = $this->usableInvitation();
        $invitation->worker->update(['name' => "山田\n# 偽の見出し"]);

        $this->runJob($invitation->id);

        Mail::assertSent(WorkerInvitationMail::class, function (WorkerInvitationMail $mail) {
            $html = $mail->render();

            return str_contains($html, '山田 # 偽の見出し 様') && substr_count($html, '<h1') === 1;
        });
    }

    public function test_企業が戻っていなければジョブは送らずに例外になる(): void
    {
        // 閉じた側に倒れる: スコープを外して全社から招待を引いたりしない
        Mail::fake();
        $invitation = $this->usableInvitation();
        Context::forget('company_id');

        try {
            $this->runJob($invitation->id);
            $this->fail('企業が決まらないまま招待を読んだ');
        } catch (\LogicException) {
            // CurrentCompany::id() が未設定で例外
        } finally {
            CurrentCompany::set($this->company->id);
        }

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    // ---- ワーカーで実行する（Context の引き継ぎ・失敗の記録・再実行・重なり） ----

    public function test_ジョブはcontextから企業を引き継ぎワーカーで実行すると送られる(): void
    {
        Mail::fake();
        $invitation = $this->usableInvitation();

        SendWorkerInvitationMail::dispatch($invitation->id)->onConnection('database');
        // ペイロード（jobs テーブル）に招待コードが入っていない
        $this->assertStringNotContainsString('ABCD2345', (string) DB::table('jobs')->value('payload'));
        Context::forget('company_id'); // setUp の企業を消す。ここから先はペイロードの Context だけが頼り
        $this->assertFalse(CurrentCompany::check());

        $this->work();

        Mail::assertSent(WorkerInvitationMail::class, 1);
        // ワーカーが戻した Context に頼らないよう、記録はスコープを通さずに読む（ApplicationConfirmedMailTest と同じ理由）
        $log = DB::table('notification_logs')->sole();
        $this->assertSame(NotificationStatus::Sent->value, $log->status);
        $this->assertSame($this->company->id, (int) $log->company_id);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_ワーカーで3回失敗すると積んだcontextで失敗が記録されqueue_retryで送信済みになる(): void
    {
        $invitation = $this->usableInvitation();
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused (user=secret)'));
        SendWorkerInvitationMail::dispatch($invitation->id)->onConnection('database');

        foreach ([0, 11, 61] as $seconds) {
            $this->travel($seconds)->seconds();
            Context::forget('company_id');
            $this->work();
        }

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $failed = DB::table('failed_jobs')->sole();
        $this->assertStringNotContainsString('ABCD2345', $failed->payload.$failed->exception); // 失敗の記録にもコードを残さない
        $log = DB::table('notification_logs')->sole();
        $this->assertSame(NotificationStatus::Failed->value, $log->status);
        $this->assertSame('TransportException: メールの送信に失敗しました。', $log->error);
        $this->assertSame($this->company->id, (int) $log->company_id);

        $this->stopFailingMailer();
        Mail::fake();
        $this->artisan('queue:retry', ['id' => ['all']])->assertExitCode(0);
        Context::forget('company_id');
        $this->work();

        Mail::assertSent(WorkerInvitationMail::class, fn (WorkerInvitationMail $mail) => str_contains($mail->render(), 'ABCD2345'));
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $log = DB::table('notification_logs')->sole();
        $this->assertSame(NotificationStatus::Sent->value, $log->status);
        $this->assertNotNull($log->sent_at);
    }

    public function test_同じ招待のジョブが走っている間の2本目は送らずfailedにもならない(): void
    {
        Mail::fake();
        $invitation = $this->usableInvitation();
        $job = new SendWorkerInvitationMail($invitation->id);
        $lock = Cache::lock($job->middleware()[1]->getLockKey($job), 120);
        $this->assertTrue($lock->get());

        SendWorkerInvitationMail::dispatch($invitation->id)->onConnection('database');
        $this->work();

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('notification_logs')->count());

        $lock->release();
    }

    public function test_重なり防止のロックのキーは招待ごとに別(): void
    {
        $a = (new SendWorkerInvitationMail(1))->middleware()[1];
        $b = (new SendWorkerInvitationMail(2))->middleware()[1];

        $this->assertNotSame($a->key, $b->key);
    }

    // ---- HTTP の入口（POST /api/workers・POST /api/workers/{worker}/invitation。docs/01 の 6.3） ----

    public function test_ワーカーを登録すると招待のジョブが積まれ応答は送信を待たない(): void
    {
        Queue::fake();
        Mail::fake();

        $response = $this->actingAsAdmin()
            ->postJson('/api/workers', ['name' => '山田太郎', 'email' => 'yamada@example.com', 'phone' => null])
            ->assertCreated()
            ->assertJsonPath('data.registered_at', null)
            ->assertJsonPath('data.can.invite', true);

        $worker = Worker::query()->sole();
        $invitation = WorkerInvitation::query()->sole();
        $this->assertSame($worker->id, $invitation->worker_id);
        $this->assertTrue($invitation->isUsable());
        $response->assertJsonPath('data.invitation_expires_at', $invitation->expires_at->toIso8601String());

        // 積んだだけで、応答の時点ではまだ送っていない（送信はワーカーが mails キューで行う）
        Queue::assertPushedOn('mails', SendWorkerInvitationMail::class, fn (SendWorkerInvitationMail $job) => $job->invitationId === $invitation->id);
        Queue::assertCount(1);
        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_登録が検証で落ちると招待は作られず積まれない(): void
    {
        Queue::fake();

        $this->actingAsAdmin()
            ->postJson('/api/workers', ['name' => '山田太郎', 'email' => ''])
            ->assertUnprocessable();

        $this->assertSame(0, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_送り直しのapiで新しい招待が積まれ前のコードは使えなくなる(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $worker = Worker::factory()->create(['name' => '山田太郎', 'email' => 'yamada@example.com', 'phone' => null]);
        $first = $this->service()->issue($worker);
        $firstCode = $first->fresh()->code;

        $response = $this->actingAsAdmin()
            ->postJson("/api/workers/{$worker->id}/invitation")
            ->assertOk();

        $second = WorkerInvitation::query()->where('id', '!=', $first->id)->sole();
        $response->assertExactJson(['data' => [
            'id' => $worker->id,
            'name' => '山田太郎',
            'email' => 'yamada@example.com',
            'phone' => null,
            'is_active' => true,
            'registered_at' => null,
            'invitation_expires_at' => $second->expires_at->toIso8601String(),
            'can' => ['invite' => true],
        ]]);

        // 前のコードでは引けても使えない（取り消し・code は NULL）。使えるのは新しい招待だけ
        $previous = WorkerInvitation::query()->where('code_hash', WorkerInvitation::hashCode($firstCode))->sole();
        $this->assertSame($first->id, $previous->id);
        $this->assertFalse($previous->isUsable());
        $this->assertNull($previous->code);
        $this->assertTrue($second->isUsable());

        Queue::assertPushed(SendWorkerInvitationMail::class, fn (SendWorkerInvitationMail $job) => $job->invitationId === $second->id);
        Queue::assertPushed(SendWorkerInvitationMail::class, 2);
    }

    public function test_無効のワーカーへの送り直しは422(): void
    {
        Queue::fake();
        $worker = Worker::factory()->inactive()->create();

        $this->actingAsAdmin()
            ->postJson("/api/workers/{$worker->id}/invitation")
            ->assertUnprocessable()
            ->assertExactJson(['message' => '無効のワーカーには招待を送れません。', 'errors' => []]);

        $this->assertSame(0, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_登録済みのワーカーへの送り直しは422(): void
    {
        Queue::fake();
        $worker = Worker::factory()->registered()->create();

        $this->actingAsAdmin()
            ->postJson("/api/workers/{$worker->id}/invitation")
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'このワーカーはすでに初回登録を済ませています。', 'errors' => []]);

        $this->assertSame(0, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_他社のワーカーへの送り直しは404(): void
    {
        Queue::fake();
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Worker::factory()->create());

        // 本番のリクエストは CurrentCompany が空の状態で始まる。setUp で入れた値に助けられないよう消してから叩く
        Context::forget('company_id');

        $this->actingAsAdmin()
            ->postJson("/api/workers/{$theirs->id}/invitation")
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);

        $this->assertSame(0, DB::table('worker_invitations')->count());
        Queue::assertNothingPushed();
    }

    public function test_未ログインだと送り直しは401(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create();
        Context::forget('company_id');

        $this->postJson("/api/workers/{$worker->id}/invitation")->assertUnauthorized();

        $this->assertSame(0, DB::table('worker_invitations')->count());
        Queue::assertNothingPushed();
    }

    public function test_登録で招待の発行が失敗するとワーカーも招待も残らずジョブも積まれない(): void
    {
        Queue::fake();
        // 招待コードが毎回重なるようにして、登録と同じトランザクションの中の発行を失敗させる
        $counter = $this->collideNextInserts(PHP_INT_MAX);
        $before = DB::table('worker_invitations')->count(); // 重なりを作るために置いた他社の招待 1 件

        $this->actingAsAdmin()
            ->postJson('/api/workers', ['name' => '山田太郎', 'email' => 'yamada@example.com'])
            ->assertStatus(500);

        $this->assertSame(5, $counter['attempts']);
        $this->assertSame(0, DB::table('workers')->where('company_id', $this->company->id)->count());
        $this->assertSame($before, DB::table('worker_invitations')->count());
        Queue::assertNothingPushed();
    }

    public function test_期限切れの招待だけのワーカーにも送り直せて応答に新しい期限が出る(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $worker = Worker::factory()->create();
        $expired = WorkerInvitation::factory()->expired()->create(['worker_id' => $worker->id]);

        $this->actingAsAdmin()
            ->postJson("/api/workers/{$worker->id}/invitation")
            ->assertOk()
            ->assertJsonPath('data.invitation_expires_at', now()->addDays((int) config('myteam.worker_invitation_days'))->toIso8601String())
            ->assertJsonPath('data.can.invite', true);

        $this->assertNotNull($expired->fresh()->revoked_at);
        Queue::assertPushed(SendWorkerInvitationMail::class, 1);
    }

    public function test_送り直しは同じワーカーに1分に1回までで2回目は429(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $worker = Worker::factory()->create();

        $this->resend($worker)->assertOk();
        $this->assertSame(1, WorkerInvitation::query()->count());

        // 1 分以内の 2 回目は 429。招待もジョブも増えない（前の招待も取り消されない）
        $this->travel(59)->seconds();
        $this->resend($worker)
            ->assertTooManyRequests()
            ->assertExactJson(['message' => '招待は同じワーカーに 1 分に 1 回まで送れます。しばらくしてからお試しください。', 'errors' => []])
            ->assertHeader('Retry-After', '1');
        $this->assertSame(1, WorkerInvitation::query()->count());
        $this->assertTrue(WorkerInvitation::query()->sole()->isUsable());
        Queue::assertPushed(SendWorkerInvitationMail::class, 1);

        // 1 分たてばまた送れる
        $this->travel(1)->seconds();
        $this->resend($worker)->assertOk();
        $this->assertSame(2, WorkerInvitation::query()->count());
        Queue::assertPushed(SendWorkerInvitationMail::class, 2);
    }

    public function test_送り直しの回数はワーカーごとに数える(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $first = Worker::factory()->create();
        $second = Worker::factory()->create();

        $this->resend($first)->assertOk();
        $this->resend($second)->assertOk();
        $this->resend($first)->assertTooManyRequests();

        $this->assertSame(1, WorkerInvitation::query()->where('worker_id', $first->id)->count());
        $this->assertSame(1, WorkerInvitation::query()->where('worker_id', $second->id)->count());
        Queue::assertPushed(SendWorkerInvitationMail::class, 2);
    }

    public function test_送り直しのidの先頭に0を付けても同じワーカーとして数える(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $worker = Worker::factory()->create();

        $this->resend($worker)->assertOk();
        $this->resend($worker, '00'.$worker->id)->assertTooManyRequests();

        $this->assertSame(1, WorkerInvitation::query()->count());
        Queue::assertPushed(SendWorkerInvitationMail::class, 1);
    }

    public function test_送り直しのidが数字でなければ404で回数を数えない(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create();

        $this->resend($worker, $worker->id.'abc')->assertNotFound();
        $this->resend($worker)->assertOk();
    }

    public function test_他社の管理者が同じidを叩いても自社の送り直しの回数を使わない(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $worker = Worker::factory()->create();
        $other = Company::factory()->create();
        $otherAdmin = User::factory()->for($other)->create();

        // 他社の管理者からは見えないので 404。throttle はバインディングの前に動くが、キーの先頭が他社の企業 ID なので自社の枠は使わない
        Context::forget('company_id');
        $this->actingAs($otherAdmin)->postJson("/api/workers/{$worker->id}/invitation")->assertNotFound();
        CurrentCompany::set($this->company->id);

        // キーに企業 ID が入っているので、自社の枠はまだ空いている
        $this->resend($worker)->assertOk();
        $this->assertSame(1, WorkerInvitation::query()->count());
    }

    // ---- メールの変更で招待を発行し直す（PUT /api/workers/{worker}。docs/01 の 3.1・4.5） ----

    public function test_未登録のワーカーのメールを直すと前のコードが使えなくなり新しい招待が積まれる(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $worker = Worker::factory()->create(['name' => '山田太郎', 'email' => 'old@example.com', 'phone' => null]);
        $first = WorkerInvitation::factory()->create(['worker_id' => $worker->id, 'code' => 'ABCD2345']);

        $response = $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'email' => 'new@example.com'])
            ->assertOk();

        $second = WorkerInvitation::query()->where('id', '!=', $first->id)->sole();
        $response->assertExactJson(['data' => [
            'id' => $worker->id,
            'name' => '山田太郎',
            'email' => 'new@example.com',
            'phone' => null,
            'is_active' => true,
            'registered_at' => null,
            'invitation_expires_at' => $second->expires_at->toIso8601String(),
            'can' => ['invite' => true],
        ]]);

        // 前のコードは引けても使えない（取り消し・code は NULL）。使えるのは新しい招待だけ
        $previous = WorkerInvitation::query()->where('code_hash', WorkerInvitation::hashCode('ABCD2345'))->sole();
        $this->assertFalse($previous->isUsable());
        $this->assertNull($previous->code);
        $this->assertTrue($second->isUsable());

        // 新しいメール宛のジョブが 1 本だけ積まれる（メールの宛先はジョブが今の workers.email から決める）
        Queue::assertPushedOn('mails', SendWorkerInvitationMail::class, fn (SendWorkerInvitationMail $job) => $job->invitationId === $second->id);
        Queue::assertPushed(SendWorkerInvitationMail::class, 1);
    }

    public function test_無効で未登録のワーカーのメールは直せて招待は積まれず前の招待は使えなくなる(): void
    {
        Queue::fake();
        $worker = Worker::factory()->inactive()->create(['name' => '山田太郎', 'email' => 'old@example.com', 'phone' => null]);
        $first = WorkerInvitation::factory()->create(['worker_id' => $worker->id]);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'email' => 'new@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.invitation_expires_at', null)
            ->assertJsonPath('data.can.invite', false);

        // 前の招待は取り消すだけで、新しい招待は作らない（有効に戻したら F-27 で送り直す）
        $this->assertSame(1, WorkerInvitation::query()->count());
        $previous = $first->fresh();
        $this->assertNotNull($previous->revoked_at);
        $this->assertNull($previous->code);
        Queue::assertNothingPushed();
    }

    public function test_メールを変えない更新では招待を送り直さない(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create(['name' => '山田太郎', 'email' => 'yamada@example.com', 'phone' => null]);
        $first = WorkerInvitation::factory()->create(['worker_id' => $worker->id]);

        // 氏名と電話だけを変える。メールの大文字小文字だけを変えても、初回登録の照合は小文字なので前のコードはそのまま使える
        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田一郎', 'email' => 'yamada@example.com', 'phone' => '090-1111-2222'])
            ->assertOk()
            ->assertJsonPath('data.name', '山田一郎');
        $this->putJson("/api/workers/{$worker->id}", ['name' => '山田一郎', 'email' => 'Yamada@Example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'yamada@example.com')
            ->assertJsonPath('data.invitation_expires_at', $first->expires_at->toIso8601String());

        $this->assertSame(1, WorkerInvitation::query()->count());
        $this->assertTrue($first->fresh()->isUsable());
        Queue::assertNothingPushed();
    }

    public function test_メールを直した後の招待のジョブは新しいメール宛に送り通知の記録の宛先も新しいメール(): void
    {
        Queue::fake(); // PUT で積まれるジョブはここでは走らせず、下で handle() を直接呼ぶ
        Mail::fake();
        $worker = Worker::factory()->create(['name' => '山田太郎', 'email' => 'old@example.com']);
        $first = WorkerInvitation::factory()->create(['worker_id' => $worker->id]);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'email' => 'New@Example.com'])
            ->assertOk();
        $second = WorkerInvitation::query()->where('id', '!=', $first->id)->sole();

        // 前の招待のジョブ（メールを直す前に積まれていたもの）は送らない
        $this->runJob($first->id);
        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());

        // 新しい招待のジョブは、新しいメール（小文字で保存したもの）宛に 1 通だけ送る
        $this->runJob($second->id);
        Mail::assertSent(WorkerInvitationMail::class, 1);
        Mail::assertSent(WorkerInvitationMail::class, fn (WorkerInvitationMail $mail) => count($mail->to) === 1 && $mail->hasTo('new@example.com'));
        Mail::assertNotSent(WorkerInvitationMail::class, fn (WorkerInvitationMail $mail) => $mail->hasTo('old@example.com'));

        $log = NotificationLog::query()->sole();
        $this->assertSame('new@example.com', $log->recipient_email);
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertSame($second->id, $log->subject_id);
    }

    public function test_期限切れの招待だけの未登録ワーカーのメールを直しても新しい招待が積まれる(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create(['name' => '山田太郎', 'email' => 'old@example.com']);
        $expired = WorkerInvitation::factory()->expired()->create(['worker_id' => $worker->id]);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'email' => 'new@example.com'])
            ->assertOk()
            ->assertJsonPath('data.can.invite', true);

        $second = WorkerInvitation::query()->where('id', '!=', $expired->id)->sole();
        $this->assertTrue($second->isUsable());
        $this->assertNull($expired->fresh()->code);
        $this->assertNotNull($expired->fresh()->revoked_at);
        Queue::assertPushed(SendWorkerInvitationMail::class, fn (SendWorkerInvitationMail $job) => $job->invitationId === $second->id);
        Queue::assertPushed(SendWorkerInvitationMail::class, 1);
    }

    /**
     * 送り直しの API を叩く。本番のリクエストは CurrentCompany が空で始まるので、setUp で入れた値を消してから叩き
     * （レート制限のキーが company ミドルウェアの後に企業を読めることを確かめる）、終わったらテストの検証のために戻す
     */
    private function resend(Worker $worker, ?string $id = null): TestResponse
    {
        Context::forget('company_id');
        $id ??= (string) $worker->id;

        try {
            return $this->actingAsAdmin()->postJson("/api/workers/{$id}/invitation");
        } finally {
            CurrentCompany::set($this->company->id);
        }
    }

    private function usableInvitation(): WorkerInvitation
    {
        $worker = Worker::factory()->create(['name' => '山田花子', 'email' => 'hanako@example.com', 'is_active' => true]);

        return WorkerInvitation::factory()->create([
            'worker_id' => $worker->id,
            'code' => 'ABCD2345',
            'expires_at' => '2036-10-10 18:30:00', // 期待値に関わるので明示する（今日より先の日時）
        ]);
    }

    private function runJob(int $invitationId): void
    {
        (new SendWorkerInvitationMail($invitationId))->handle(app(NotificationLogService::class));
    }

    /**
     * 送信に 1 回失敗し、打ち切られて failed() が呼ばれた状態にする（記録は failed）。モックは外して返す
     */
    private function failOnce(int $invitationId): void
    {
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused'));
        try {
            $this->runJob($invitationId);
            $this->fail('送信の例外が握りつぶされた');
        } catch (TransportException $e) {
            (new SendWorkerInvitationMail($invitationId))->failed($e);
        }
        $this->stopFailingMailer();
        $this->assertSame(NotificationStatus::Failed, NotificationLog::query()->sole()->status);
    }

    /**
     * ワーカーを 1 回だけ回す（make horizon の代わり）。--sleep=0 で取り出せるジョブがなくても待たずに終わる
     */
    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'mails', '--once' => true, '--sleep' => 0])->assertExitCode(0);
    }

    /**
     * Mail::shouldReceive() のモックを外し、本物の MailManager に戻す（MailFake は今のマネージャを包むので、モックのままだと fake できない）
     */
    private function stopFailingMailer(): void
    {
        Mail::clearResolvedInstance('mail.manager');
        $this->app->forgetInstance('mail.manager');
    }
}
