<?php

namespace Tests\Feature;

use App\Events\WorkerInvited;
use App\Exceptions\DomainException;
use App\Jobs\SendWorkerInvitationMail;
use App\Models\Company;
use App\Models\Worker;
use App\Models\WorkerInvitation;
use App\Services\WorkerInvitationService;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * F-26・F-27 招待の発行（docs/01 の 3.2・4.5・非同期の契約、async-rules）。
 *
 * - 発行（WorkerInvitationService::issue()）は直接呼ぶ。HTTP の入口（POST /api/workers/{worker}/invitation）は別のタスク
 * - 「積まれたか」は Queue::fake() で確かめる。「実行したらどうなるか」（メールの送信）は T5 で handle() を直接呼んで確かめる
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

    // ---- 追加（tester）: 取り消しの範囲 ----

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

    // ---- 追加（tester）: 他社・ロックして読み直した後の判定 ----

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

    // ---- 追加（tester）: イベント ----

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

    // ---- 追加（tester）: コードの重なり ----

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
}
