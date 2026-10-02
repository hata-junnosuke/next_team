<?php

namespace Tests\Feature;

use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Jobs\SendPostingCancelledMail;
use App\Mail\PostingCancelledMail;
use App\Models\Application;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\Worker;
use App\Services\JobPostingService;
use App\Services\NotificationLogService;
use App\Support\CurrentCompany;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * F-21 中止通知（docs/01 8 章、docs/03 3.2、async-rules）。
 *
 * - 「積まれたか」は Queue::fake() で確かめる（ジョブは実行しない）
 * - 「実行したらどうなるか」はジョブの handle() を直接呼んで確かめる（Mail::fake() で送信を記録する）
 * - 流量の制限（RateLimited）は、database キューに実際に積んでワーカー（queue:work）で確かめる
 * 失敗の記録と再実行は FailedJobTest、二重送信しないことは NotificationIdempotencyTest。
 */
class PostingCancelledMailTest extends TestCase
{
    // ---- 積まれたか（Queue::fake） ----

    public function test_中止すると確定済みだった人数分のジョブが積まれる(): void
    {
        Queue::fake();
        $posting = JobPosting::factory()->create(['capacity' => 3]);
        $ids = Application::factory()->for($posting)->confirmed()->count(3)->create()->pluck('id')->all();

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        // 1 つのジョブで 3 通送らない。1 応募 1 ジョブ（docs/03 3.2「失敗の分離」）
        Queue::assertPushedOn('mails', SendPostingCancelledMail::class);
        Queue::assertCount(3);
        foreach ($ids as $id) {
            Queue::assertPushed(SendPostingCancelledMail::class, fn (SendPostingCancelledMail $job) => $job->applicationId === $id);
        }
    }

    public function test_応募中だった人には積まれない(): void
    {
        Queue::fake();
        $posting = JobPosting::factory()->create(['capacity' => 3]);
        $confirmed = Application::factory()->for($posting)->confirmed()->create();
        $applied = Application::factory()->for($posting)->create();

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        Queue::assertCount(1);
        Queue::assertPushed(SendPostingCancelledMail::class, fn (SendPostingCancelledMail $job) => $job->applicationId === $confirmed->id);
        Queue::assertNotPushed(SendPostingCancelledMail::class, fn (SendPostingCancelledMail $job) => $job->applicationId === $applied->id);
    }

    public function test_確定済みの人がいない募集を中止しても何も積まれない(): void
    {
        Queue::fake();
        $posting = JobPosting::factory()->create();
        Application::factory()->for($posting)->create();
        Application::factory()->for($posting)->rejected()->create();
        Application::factory()->for($posting)->cancelled(CancelReason::Worker)->create();

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_中止のトランザクションがロールバックされたら何も積まれない(): void
    {
        Queue::fake();
        $posting = JobPosting::factory()->create();
        Application::factory()->for($posting)->confirmed()->create();

        // 中止そのものは成功したが、外側のトランザクションが巻き戻った（ShouldDispatchAfterCommit でイベントが捨てられる）
        DB::beginTransaction();
        app(JobPostingService::class)->cancel($posting);
        DB::rollBack();

        Queue::assertNothingPushed();
    }

    public function test_他社の募集を中止しようとすると404で何も積まれない(): void
    {
        Queue::fake();
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, function () {
            $posting = JobPosting::factory()->create();
            Application::factory()->for($posting)->confirmed()->create();

            return $posting;
        });
        Context::forget('company_id'); // ミドルウェアが企業を決めるところから通す

        $this->actingAsAdmin()->postJson("/api/job-postings/{$theirs->id}/cancel")->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_応募のキャンセルや締切では中止通知ジョブは積まれない(): void
    {
        Queue::fake();
        $confirmed = Application::factory()->confirmed()->create();
        $posting = JobPosting::factory()->create();
        Application::factory()->for($posting)->confirmed()->create();

        // 企業都合でも、応募 1 件のキャンセル（F-13）は中止通知ではない
        $this->actingAsAdmin()->postJson("/api/applications/{$confirmed->id}/cancel", ['reason' => 'company'])->assertOk();
        $this->postJson("/api/job-postings/{$posting->id}/close")->assertOk();

        Queue::assertNotPushed(SendPostingCancelledMail::class);
    }

    public function test_積んだジョブのペイロードは応募idだけで宛先や氏名を含まない(): void
    {
        $application = $this->cancelledApplication();

        SendPostingCancelledMail::dispatch($application->id)->onConnection('database');

        $row = DB::table('jobs')->sole();
        $this->assertSame('mails', $row->queue);
        $this->assertStringNotContainsString('hanako@example.com', $row->payload);
        $this->assertStringNotContainsString('山田花子', $row->payload);
        $this->assertStringNotContainsString('App\\\\Models\\\\Application', $row->payload); // モデルを直列化していない
    }

    // ---- 実行したらどうなるか（handle() を直接呼ぶ） ----

    public function test_ジョブを実行すると企業都合キャンセルの通知が1通送られる(): void
    {
        Mail::fake();
        $application = $this->cancelledApplication();

        $this->runJob($application->id);

        Mail::assertSent(PostingCancelledMail::class, 1);
        Mail::assertSent(PostingCancelledMail::class, function (PostingCancelledMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('hanako@example.com')
                && count($mail->to) === 1 && $mail->cc === [] && $mail->bcc === []
                && $mail->hasSubject('【中止】ホールスタッフの募集が中止になりました')
                && str_contains($html, '山田花子 様')
                && str_contains($html, '2026年10月10日（土） 09:00〜18:00')
                && str_contains($html, '渋谷店')
                && str_contains($html, 'ホールスタッフ')
                && str_contains($html, '企業都合')
                // ワーカーはログインできないので、管理画面（FRONTEND_URL）にもヘッダーの APP_URL にもリンクしない
                && ! str_contains($html, '<a ')
                && ! str_contains($html, 'http://localhost:3002');
        });

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationKind::PostingCancelled, $log->kind);
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertSame("posting_cancelled:application:{$application->id}", $log->dedupe_key);
        $this->assertSame(['application', $application->id], [$log->subject_type, $log->subject_id]);
    }

    public function test_中止apiで3人に1通ずつ送られる(): void
    {
        Mail::fake();
        $posting = JobPosting::factory()->create(['capacity' => 3]);
        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            Application::factory()->for($posting)->for(Worker::factory()->state(['email' => $email]))->confirmed()->create();
        }

        // phpunit.xml は QUEUE_CONNECTION=sync なので、コミット後にリスナが積んだジョブがその場で実行される
        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        Mail::assertSent(PostingCancelledMail::class, 3);
        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            Mail::assertSent(PostingCancelledMail::class, fn (PostingCancelledMail $mail) => $mail->hasTo($email) && count($mail->to) === 1);
        }
        $this->assertSame(3, NotificationLog::query()->where('status', NotificationStatus::Sent)->count());
    }

    public function test_ワーカー都合のキャンセルには送らない(): void
    {
        Mail::fake();
        $posting = JobPosting::factory()->create(['status' => JobPostingStatus::Cancelled]);
        $application = Application::factory()->for($posting)->cancelled(CancelReason::Worker)->create();

        $this->runJob($application->id);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_確定中や応募中や却下の応募には送らない(): void
    {
        Mail::fake();
        $confirmed = Application::factory()->confirmed()->create();
        $applied = Application::factory()->create();
        $rejected = Application::factory()->rejected()->create();

        $this->runJob($confirmed->id);
        $this->runJob($applied->id);
        $this->runJob($rejected->id);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_募集が中止でなければ企業都合のキャンセルでも送らない(): void
    {
        // 応募 1 件の企業都合キャンセル（F-13）は中止通知の対象ではない（募集は募集中のまま）
        Mail::fake();
        $application = Application::factory()->cancelled(CancelReason::Company)->create();

        $this->runJob($application->id);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_応募が見つからなければ送らない(): void
    {
        Mail::fake();

        $this->runJob(999_999);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_他社の応募のジョブは送らない(): void
    {
        Mail::fake();
        $otherCompany = Company::factory()->create();
        $otherId = CurrentCompany::runAs($otherCompany->id, fn () => $this->cancelledApplication()->id);

        // 企業を取り違えたジョブ（自社の Context で他社の応募ID）。グローバルスコープで見えないので送らない
        $this->runJob($otherId);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::withoutGlobalScopes()->count()); // 全社の行を数えるため（他社の行も作られていないこと）
    }

    public function test_中止後にメールアドレスが変わっていたら実行時のアドレスに送る(): void
    {
        Mail::fake();
        $application = $this->cancelledApplication();
        $application->worker->update(['email' => 'new-hanako@example.com']);

        $this->runJob($application->id);

        Mail::assertSent(PostingCancelledMail::class, fn (PostingCancelledMail $mail) => $mail->hasTo('new-hanako@example.com')
            && ! $mail->hasTo('hanako@example.com'));
    }

    public function test_名前や拠点名のmarkdown記号はリンクや表の列にならない(): void
    {
        Mail::fake();
        $application = $this->cancelledApplication();
        $application->worker->update(['name' => '[花子](https://evil.example)']);
        $application->jobPosting->location->update(['name' => '渋谷|店[x](https://evil.example)']);

        $this->runJob($application->id);

        Mail::assertSent(PostingCancelledMail::class, function (PostingCancelledMail $mail) {
            $html = $mail->render();
            preg_match('#<td[^>]*>拠点</td>\s*<td[^>]*>(.*?)</td>\s*</tr>#s', $html, $row);

            return ! str_contains($html, '<a ')
                && ! str_contains($html, 'href="https://evil.example"')
                && isset($row[1]) && str_contains($row[1], '渋谷') && str_contains($row[1], '店') && str_contains($row[1], 'evil.example');
        });
    }

    public function test_氏名の改行は空白になり見出しや段落を作らない(): void
    {
        Mail::fake();
        $application = $this->cancelledApplication();
        $application->worker->update(['name' => "山田\n# 偽の見出し"]);

        $this->runJob($application->id);

        Mail::assertSent(PostingCancelledMail::class, function (PostingCancelledMail $mail) {
            $html = $mail->render();

            return str_contains($html, '山田 # 偽の見出し 様') && substr_count($html, '<h1') === 1;
        });
    }

    public function test_claimの前に打ち切られてもまだ対象ならfailedが届いていない記録を作る(): void
    {
        // 例: 流量制限で戻され続けて retryUntil を過ぎた（handle() に 1 度も入っていない）
        $application = $this->cancelledApplication();

        (new SendPostingCancelledMail($application->id))->failed(new \RuntimeException('期限切れ'));

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertSame(NotificationKind::PostingCancelled, $log->kind);
        $this->assertSame('hanako@example.com', $log->recipient_email);
    }

    // ---- ジョブの設定 ----

    public function test_ジョブはmailsキューで例外3回か積んでから60分で打ち切りbackoffは10秒と60秒(): void
    {
        $this->freezeSecond();
        $job = new SendPostingCancelledMail(1);

        $this->assertSame('mails', $job->queue);
        // 試行の回数では打ち切らない（キューへ戻された回を失敗に数えない）。例外 3 回か retryUntil で打ち切る
        $this->assertSame(0, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertEquals(now()->addMinutes(60), $job->retryUntil());
        $this->assertSame([10, 60], $job->backoff());
    }

    public function test_ミドルウェアは通知のキーのwithout_overlappingとメールの流量制限(): void
    {
        $middleware = (new SendPostingCancelledMail(15))->middleware();

        $this->assertCount(2, $middleware);
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[1]);
        $this->assertSame('posting_cancelled:application:15', $middleware[1]->key);
        $this->assertNull($middleware[1]->releaseAfter); // dontRelease(): 重なった 2 本目は戻さずに捨てる
        $this->assertSame(120, $middleware[1]->expiresAfter);
    }

    public function test_メールの流量制限は設定の1分あたりの件数で全社共通(): void
    {
        config(['myteam.mails_per_minute' => 7]);

        $limit = RateLimiter::limiter('mails')(new SendPostingCancelledMail(1));

        $this->assertSame(7, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('', $limit->key); // メールサーバーの流量なので企業ごとに分けない
    }

    public function test_1分あたりの上限を超えた分は送らずにキューへ戻され後で送られる(): void
    {
        Mail::fake();
        config(['myteam.mails_per_minute' => 1]);
        $first = $this->cancelledApplication();
        $second = Application::factory()->for($first->jobPosting)->for(Worker::factory()->state(['email' => 'jiro@example.com']))
            ->cancelled(CancelReason::Company)->create();

        SendPostingCancelledMail::dispatch($first->id)->onConnection('database');
        SendPostingCancelledMail::dispatch($second->id)->onConnection('database');
        $this->work();
        $this->work();

        // 2 本目は上限に当たり、送られずにキューへ戻された（失敗ではない）
        Mail::assertSent(PostingCancelledMail::class, 1);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // 1 分経てば送られる
        $this->travel(2)->minutes();
        $this->work();

        Mail::assertSent(PostingCancelledMail::class, 2);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // ---- テスト補強（tester）: 対象の絞り込み・2 人目以降・流量制限・他社 ----

    public function test_確定と応募中と却下とキャンセル済みが混ざった募集を中止すると確定済みだった人だけに積まれる(): void
    {
        Queue::fake();
        $posting = JobPosting::factory()->create(['capacity' => 5]);
        $confirmed = Application::factory()->for($posting)->confirmed()->count(2)->create()->pluck('id')->all();
        $others = [
            Application::factory()->for($posting)->create()->id,                                   // 応募中 → 中止で却下
            Application::factory()->for($posting)->rejected()->create()->id,                       // 却下済み
            Application::factory()->for($posting)->cancelled(CancelReason::Worker)->create()->id,  // ワーカー都合でキャンセル済み
            Application::factory()->for($posting)->cancelled(CancelReason::Company)->create()->id, // 中止の前に F-13 で企業都合キャンセル済み
        ];

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        Queue::assertCount(2);
        foreach ($confirmed as $id) {
            Queue::assertPushed(SendPostingCancelledMail::class, fn (SendPostingCancelledMail $job) => $job->applicationId === $id);
        }
        foreach ($others as $id) {
            Queue::assertNotPushed(SendPostingCancelledMail::class, fn (SendPostingCancelledMail $job) => $job->applicationId === $id);
        }
    }

    public function test_中止の再実行は422で中止通知ジョブを積み直さない(): void
    {
        $posting = JobPosting::factory()->create();
        Application::factory()->for($posting)->confirmed()->create();
        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        Queue::fake(); // 1 回目の中止で積まれた分は数えない
        $this->postJson("/api/job-postings/{$posting->id}/cancel")->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_未ログインで中止しようとすると401で何も積まれない(): void
    {
        Queue::fake();
        $posting = JobPosting::factory()->create();
        Application::factory()->for($posting)->confirmed()->create();

        $this->postJson("/api/job-postings/{$posting->id}/cancel")->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_自社の募集を中止しても他社の確定済みの応募には積まれない(): void
    {
        Queue::fake();
        $other = Company::factory()->create();
        $theirId = CurrentCompany::runAs($other->id, fn () => Application::factory()->confirmed()->create()->id);
        $posting = JobPosting::factory()->create();
        $mine = Application::factory()->for($posting)->confirmed()->create();
        Context::forget('company_id');

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        Queue::assertCount(1);
        Queue::assertPushed(SendPostingCancelledMail::class, fn (SendPostingCancelledMail $job) => $job->applicationId === $mine->id);
        Queue::assertNotPushed(SendPostingCancelledMail::class, fn (SendPostingCancelledMail $job) => $job->applicationId === $theirId);
    }

    public function test_混ざった募集を中止すると確定済みだった人にだけ1通ずつ届き既にキャンセルだった人には届かない(): void
    {
        Mail::fake();
        $posting = JobPosting::factory()->create(['capacity' => 5]);
        $make = fn (string $email) => Application::factory()->for($posting)->for(Worker::factory()->state(['email' => $email]));
        $make('confirmed1@example.com')->confirmed()->create();
        $make('confirmed2@example.com')->confirmed()->create();
        $make('applied@example.com')->create();
        $make('rejected@example.com')->rejected()->create();
        $make('worker-cancel@example.com')->cancelled(CancelReason::Worker)->create();
        $make('company-cancel@example.com')->cancelled(CancelReason::Company)->create(); // 中止の前に F-13 で企業都合キャンセル済み

        // sync キューなので、コミット後に積まれたジョブがその場で実行される
        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        Mail::assertSent(PostingCancelledMail::class, 2);
        foreach (['confirmed1@example.com', 'confirmed2@example.com'] as $email) {
            Mail::assertSent(PostingCancelledMail::class, fn (PostingCancelledMail $mail) => $mail->hasTo($email));
        }
        foreach (['applied@example.com', 'rejected@example.com', 'worker-cancel@example.com', 'company-cancel@example.com'] as $email) {
            Mail::assertNotSent(PostingCancelledMail::class, fn (PostingCancelledMail $mail) => $mail->hasTo($email));
        }
        $this->assertSame(2, NotificationLog::query()->count());
    }

    public function test_ワーカーで実行しても確定済みだった3人全員に1通ずつ届き2人目以降が潰れない(): void
    {
        // 中止 API が積むジョブを database キューに溜め、ワーカーで 1 本ずつ実行する（ロックや流量制限も本物が動く）
        Mail::fake();
        config(['queue.default' => 'database']);
        $posting = JobPosting::factory()->create(['capacity' => 3]);
        $emails = ['a@example.com', 'b@example.com', 'c@example.com'];
        foreach ($emails as $email) {
            Application::factory()->for($posting)->for(Worker::factory()->state(['email' => $email]))->confirmed()->create();
        }

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();
        $this->assertSame(3, DB::table('jobs')->where('queue', 'mails')->count());
        foreach ($emails as $_) {
            Context::forget('company_id'); // 企業はジョブのペイロードの Context から戻る
            $this->work();
        }

        Mail::assertSent(PostingCancelledMail::class, 3);
        foreach ($emails as $email) {
            Mail::assertSent(PostingCancelledMail::class, fn (PostingCancelledMail $mail) => $mail->hasTo($email));
        }
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(3, DB::table('notification_logs')->where('status', NotificationStatus::Sent->value)->count());
    }

    public function test_重なり防止のロックのキーは応募ごとに別で他の人のジョブを止めない(): void
    {
        $a = (new SendPostingCancelledMail(1))->middleware()[1];
        $b = (new SendPostingCancelledMail(2))->middleware()[1];

        $this->assertNotSame($a->key, $b->key);
    }

    public function test_流量制限で戻された回は通知の記録を作らず枠が空いてから送られて記録される(): void
    {
        Mail::fake();
        config(['myteam.mails_per_minute' => 1]);
        $first = $this->cancelledApplication();
        $second = Application::factory()->for($first->jobPosting)->for(Worker::factory()->state(['email' => 'jiro@example.com']))
            ->cancelled(CancelReason::Company)->create();

        SendPostingCancelledMail::dispatch($first->id)->onConnection('database');
        SendPostingCancelledMail::dispatch($second->id)->onConnection('database');
        $this->work();
        Context::forget('company_id');
        $this->work();

        // 2 本目は claim の前に戻されたので、記録の行はまだない（pending も failed も作らない）
        $logs = DB::table('notification_logs')->pluck('status', 'subject_id')->all();
        $this->assertSame([$first->id => NotificationStatus::Sent->value], $logs);

        $this->travel(2)->minutes();
        Context::forget('company_id');
        $this->work();

        Mail::assertSent(PostingCancelledMail::class, fn (PostingCancelledMail $mail) => $mail->hasTo('jiro@example.com'));
        $this->assertSame(NotificationStatus::Sent->value, DB::table('notification_logs')->where('subject_id', $second->id)->value('status'));
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    private function cancelledApplication(): Application
    {
        $location = Location::factory()->create(['name' => '渋谷店']);
        $posting = JobPosting::factory()->for($location)->create([
            'job_title' => 'ホールスタッフ',
            'work_date' => '2026-10-10',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'hourly_wage' => 1300,
            'status' => JobPostingStatus::Cancelled,
        ]);
        $worker = Worker::factory()->create(['name' => '山田花子', 'email' => 'hanako@example.com']);

        return Application::factory()->for($posting)->for($worker)->cancelled(CancelReason::Company)->create();
    }

    private function runJob(int $applicationId): void
    {
        (new SendPostingCancelledMail($applicationId))->handle(app(NotificationLogService::class));
    }

    /**
     * ワーカーを 1 回だけ回す（make horizon の代わり）。--sleep=0 で取り出せるジョブがなくても待たずに終わる
     */
    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'mails', '--once' => true, '--sleep' => 0])->assertExitCode(0);
    }
}
