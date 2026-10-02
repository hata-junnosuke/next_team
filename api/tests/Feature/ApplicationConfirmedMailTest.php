<?php

namespace Tests\Feature;

use App\Enums\CancelReason;
use App\Enums\NotificationStatus;
use App\Events\ApplicationConfirmed;
use App\Jobs\SendApplicationConfirmedMail;
use App\Mail\ApplicationConfirmedMail;
use App\Models\Application;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\Worker;
use App\Services\ApplicationService;
use App\Services\NotificationLogService;
use App\Support\CurrentCompany;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * F-20 確定通知（docs/01 8 章、docs/03 3.1、async-rules）。
 *
 * - 「積まれたか」は Queue::fake() で確かめる（ジョブは実行しない）
 * - 「実行したらどうなるか」はジョブの handle() を直接呼んで確かめる（Mail::fake() で送信を記録する）
 * - Context の引き継ぎ（docs/01 13 章 #4）は、database キューに実際に積んでワーカー（queue:work）で実行して確かめる。
 *   setUp の CurrentCompany ではなく「積んだジョブのペイロードの Context」で動くことを示すため、積んだ後に Context を消す
 * 二重送信しないことは NotificationIdempotencyTest。
 */
class ApplicationConfirmedMailTest extends TestCase
{
    // ---- 積まれたか（Queue::fake） ----

    public function test_承認するとmailsキューに確定通知ジョブが積まれる(): void
    {
        Queue::fake();
        $application = Application::factory()->create();

        $this->actingAsAdmin()->postJson("/api/applications/{$application->id}/confirm")->assertOk();

        Queue::assertPushedOn('mails', SendApplicationConfirmedMail::class, fn (SendApplicationConfirmedMail $job) => $job->applicationId === $application->id);
        Queue::assertCount(1);
    }

    public function test_定員エラーで巻き戻ったら何も積まれない(): void
    {
        Queue::fake();
        $posting = JobPosting::factory()->create(['capacity' => 1]);
        Application::factory()->for($posting)->confirmed()->create();
        $applied = Application::factory()->for($posting)->create();

        $this->actingAsAdmin()->postJson("/api/applications/{$applied->id}/confirm")->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_承認のトランザクションがロールバックされたら何も積まれない(): void
    {
        Queue::fake();
        $application = Application::factory()->create();

        // 承認そのものは成功したが、外側のトランザクションが巻き戻った（ShouldDispatchAfterCommit でイベントが捨てられる）
        DB::beginTransaction();
        app(ApplicationService::class)->confirm($application);
        DB::rollBack();

        Queue::assertNothingPushed();
    }

    // ---- 実行したらどうなるか（handle() を直接呼ぶ） ----

    public function test_ジョブを実行すると確定通知が1通送られ日時拠点職種時給を含む(): void
    {
        Mail::fake();
        $application = $this->confirmedApplication();

        $this->runJob($application->id);

        Mail::assertSent(ApplicationConfirmedMail::class, 1);
        Mail::assertSent(ApplicationConfirmedMail::class, function (ApplicationConfirmedMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('hanako@example.com')
                && $mail->hasSubject('【確定】ホールスタッフの勤務が確定しました')
                && str_contains($html, '2026年10月10日（土） 09:00〜18:00')
                && str_contains($html, '渋谷店')
                && str_contains($html, 'ホールスタッフ')
                && str_contains($html, '1,300円')
                // ワーカーはログインできないので、管理画面（FRONTEND_URL）にもヘッダーの APP_URL にもリンクしない
                && ! str_contains($html, '<a ')
                && ! str_contains($html, 'http://localhost:3002');
        });
    }

    public function test_時給は募集の今の値ではなく確定時の値を載せる(): void
    {
        Mail::fake();
        $application = $this->confirmedApplication();
        $application->jobPosting->update(['hourly_wage' => 2000]);

        $this->runJob($application->id);

        Mail::assertSent(ApplicationConfirmedMail::class, fn (ApplicationConfirmedMail $mail) => str_contains($mail->render(), '1,300円')
            && ! str_contains($mail->render(), '2,000円'));
    }

    public function test_確定後にキャンセルされていたら送らない(): void
    {
        Mail::fake();
        $application = Application::factory()->cancelled(CancelReason::Worker)->create();

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
        $otherId = CurrentCompany::runAs($otherCompany->id, fn () => Application::factory()->confirmed()->create()->id);

        // 企業を取り違えたジョブ（自社の Context で他社の応募ID）。グローバルスコープで見えないので送らない
        $this->runJob($otherId);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::withoutGlobalScopes()->count()); // 全社の行を数えるため（他社の行も作られていないこと）
    }

    // ---- Context の引き継ぎ（実際にキューに積んでワーカーで実行する） ----

    public function test_ジョブはcontextから企業を引き継ぐ(): void
    {
        Mail::fake();
        $application = $this->confirmedApplication();

        SendApplicationConfirmedMail::dispatch($application->id)->onConnection('database');
        Context::forget('company_id'); // setUp の企業を消す。ここから先はペイロードの Context だけが頼り
        $this->assertFalse(CurrentCompany::check());

        $this->work();

        Mail::assertSent(ApplicationConfirmedMail::class, 1);
        // 記録はスコープを通さずに読む。ワーカーはテストと同じプロセスで動き、ジョブの Context をこのプロセスに戻したまま
        // 終わる（副作用）ので、NotificationLog::query() だとワーカーが戻した企業で読めてしまい、確かめたいことと混ざる
        $log = DB::table('notification_logs')->sole();
        $this->assertSame(NotificationStatus::Sent->value, $log->status);
        $this->assertSame($this->company->id, (int) $log->company_id);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_3回失敗するとfailedが積んだジョブのcontextで呼ばれnotification_logsがfailedになる(): void
    {
        $application = $this->confirmedApplication();
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused (user=secret)'));

        SendApplicationConfirmedMail::dispatch($application->id)->onConnection('database');

        // backoff は [10, 60]。試行のたびに Context を消し、ワーカーがペイロードから戻すことを確かめる
        foreach ([0, 11, 61] as $seconds) {
            $this->travel($seconds)->seconds();
            Context::forget('company_id');
            $this->work();
        }

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());

        // failed() の中の markFailedByKey はグローバルスコープを通る。Context が戻っていなければ LogicException で記録されない。
        // テストのプロセスの Context に頼らないよう、記録はスコープを通さずに読む
        $log = DB::table('notification_logs')->sole();
        $this->assertSame(NotificationStatus::Failed->value, $log->status);
        $this->assertSame('TransportException: メールの送信に失敗しました。', $log->error);
        $this->assertSame($this->company->id, (int) $log->company_id);
        $this->assertStringNotContainsString('secret', $log->error);
    }

    public function test_ジョブはmailsキューで例外3回か積んでから60分で打ち切りbackoffは10秒と60秒(): void
    {
        $this->freezeSecond();
        $job = new SendApplicationConfirmedMail(1);

        $this->assertSame('mails', $job->queue);
        // 試行の回数では打ち切らない（キューへ戻された回を失敗に数えない）。例外 3 回か retryUntil で打ち切る
        $this->assertSame(0, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertEquals(now()->addMinutes(60), $job->retryUntil());
        $this->assertSame([10, 60], $job->backoff());
    }

    // ---- テスト補強（P4-02 tester）: 積まれたか（fake） ----

    public function test_承認の応答はメールを送らずジョブを積むだけで返る(): void
    {
        Queue::fake();
        Mail::fake();
        $application = Application::factory()->create();

        // F-20「確定操作の応答はメール送信の完了を待たない」: 応答の時点ではメールは 1 通も出ていない
        $this->actingAsAdmin()->postJson("/api/applications/{$application->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'confirmed');

        Mail::assertNothingOutgoing();
        Queue::assertPushedOn('mails', SendApplicationConfirmedMail::class);
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_他社の応募を承認しようとすると404で何も積まれない(): void
    {
        Queue::fake();
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Application::factory()->create());
        Context::forget('company_id'); // ミドルウェアが企業を決めるところから通す

        $this->actingAsAdmin()->postJson("/api/applications/{$theirs->id}/confirm")->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_却下とキャンセルでは確定通知ジョブは積まれない(): void
    {
        Queue::fake();
        $applied = Application::factory()->create();
        $confirmed = Application::factory()->confirmed()->create();

        $this->actingAsAdmin()->postJson("/api/applications/{$applied->id}/reject")->assertOk();
        $this->postJson("/api/applications/{$confirmed->id}/cancel", ['reason' => 'company'])->assertOk();

        Queue::assertNotPushed(SendApplicationConfirmedMail::class);
    }

    public function test_積んだジョブのペイロードは応募idだけで宛先や氏名を含まない(): void
    {
        $application = $this->confirmedApplication();

        SendApplicationConfirmedMail::dispatch($application->id)->onConnection('database');

        $row = DB::table('jobs')->sole();
        $this->assertSame('mails', $row->queue);
        $this->assertStringNotContainsString('hanako@example.com', $row->payload);
        $this->assertStringNotContainsString('山田花子', $row->payload);
        $this->assertStringNotContainsString('App\\\\Models\\\\Application', $row->payload); // モデルを直列化していない
    }

    // ---- テスト補強（P4-02 tester）: 実行したらどうなるか（handle() / failed() を直接呼ぶ） ----

    public function test_承認の直後にapiでキャンセルされた応募には送らない(): void
    {
        Mail::fake();
        // 承認では積ませない（sync キューだとその場で送ってしまう）。ワーカーが拾う前にキャンセルされた状況を作る
        Event::fake([ApplicationConfirmed::class]);
        $application = Application::factory()->create();

        $this->actingAsAdmin()->postJson("/api/applications/{$application->id}/confirm")->assertOk();
        $this->postJson("/api/applications/{$application->id}/cancel", ['reason' => 'company'])->assertOk();

        $this->runJob($application->id);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_応募中と却下の応募には送らない(): void
    {
        Mail::fake();
        $applied = Application::factory()->create();
        $rejected = Application::factory()->rejected()->create();

        $this->runJob($applied->id);
        $this->runJob($rejected->id);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_確定後にワーカーが無効になっても確定通知は送る(): void
    {
        // 要件 F-03「既存の確定は影響を受けない」。無効化は新規応募を止めるだけで、確定済みの勤務の知らせは止めない
        Mail::fake();
        $application = $this->confirmedApplication();
        $application->worker->update(['is_active' => false]);

        $this->runJob($application->id);

        Mail::assertSent(ApplicationConfirmedMail::class, fn (ApplicationConfirmedMail $mail) => $mail->hasTo('hanako@example.com'));
        $this->assertSame(NotificationStatus::Sent, NotificationLog::query()->sole()->status);
    }

    public function test_確定後にメールアドレスが変わっていたら実行時のアドレスに送る(): void
    {
        Mail::fake();
        $application = $this->confirmedApplication();
        $application->worker->update(['email' => 'new-hanako@example.com']);

        $this->runJob($application->id);

        Mail::assertSent(ApplicationConfirmedMail::class, fn (ApplicationConfirmedMail $mail) => $mail->hasTo('new-hanako@example.com')
            && ! $mail->hasTo('hanako@example.com'));
        $this->assertSame('new-hanako@example.com', NotificationLog::query()->sole()->recipient_email);
    }

    public function test_宛先はそのワーカー1人だけでccとbccはない(): void
    {
        Mail::fake();
        Worker::factory()->create(['email' => 'other@example.com']);
        $application = $this->confirmedApplication();

        $this->runJob($application->id);

        Mail::assertSent(ApplicationConfirmedMail::class, function (ApplicationConfirmedMail $mail) {
            return count($mail->to) === 1 && $mail->hasTo('hanako@example.com')
                && $mail->cc === [] && $mail->bcc === []
                && str_contains($mail->render(), '山田花子 様');
        });
    }

    public function test_送信で例外が出ると記録はpendingのまま例外を投げ直し再試行に回る(): void
    {
        $application = $this->confirmedApplication();
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused'));

        try {
            $this->runJob($application->id);
            $this->fail('送信の例外が握りつぶされた（再試行されなくなる）');
        } catch (TransportException) {
            // 例外がワーカーまで届くので、tries と backoff に従って再試行される
        }

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Pending, $log->status);
        $this->assertNull($log->sent_at);
    }

    public function test_claimの前に失敗したらfailedは記録を作らず例外にならない(): void
    {
        // 送るべき対象でない（応募が見つからない・もう確定ではない）なら、送る通知がないので notification_logs の行は作らない
        // （failed_jobs には残る）
        $cancelled = Application::factory()->cancelled(CancelReason::Worker)->create();

        (new SendApplicationConfirmedMail(999_999))->failed(new \RuntimeException('boom'));
        (new SendApplicationConfirmedMail($cancelled->id))->failed(new \RuntimeException('boom'));

        $this->assertSame(0, NotificationLog::withoutGlobalScopes()->count()); // 全社の行を数えるため
    }

    public function test_claimの前に打ち切られてもまだ確定ならfailedが届いていない記録を作る(): void
    {
        // 例: 流量制限で戻され続けて retryUntil を過ぎた（handle() に 1 度も入っていない）
        $application = $this->confirmedApplication();

        (new SendApplicationConfirmedMail($application->id))->failed(new \RuntimeException('期限切れ'));

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertSame('hanako@example.com', $log->recipient_email);
        $this->assertSame("application_confirmed:application:{$application->id}", $log->dedupe_key);
    }

    public function test_送信済みの後にfailedが呼ばれても失敗に戻さない(): void
    {
        Mail::fake();
        $application = $this->confirmedApplication();
        $this->runJob($application->id);

        (new SendApplicationConfirmedMail($application->id))->failed(new TransportException('late'));

        $this->assertSame(NotificationStatus::Sent, NotificationLog::query()->sole()->status);
    }

    public function test_3回失敗した後にqueue_retryで再実行すると1通送られsentになる(): void
    {
        $application = $this->confirmedApplication();
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused'));
        SendApplicationConfirmedMail::dispatch($application->id)->onConnection('database');
        foreach ([0, 11, 61] as $seconds) {
            $this->travel($seconds)->seconds();
            Context::forget('company_id');
            $this->work();
        }
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(NotificationStatus::Failed->value, DB::table('notification_logs')->sole()->status);

        // メールサーバーが直った → failed_jobs から再実行（要件 7 章「失敗した送信は記録が残り、再実行できる」）
        $this->stopFailingMailer();
        Mail::fake();
        $this->artisan('queue:retry', ['id' => ['all']])->assertExitCode(0);
        Context::forget('company_id');
        $this->work();

        Mail::assertSent(ApplicationConfirmedMail::class, 1);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $log = DB::table('notification_logs')->sole(); // ワーカーの後のテストのプロセスの Context に頼らない
        $this->assertSame(NotificationStatus::Sent->value, $log->status);
        $this->assertNotNull($log->sent_at);
    }

    public function test_ミドルウェアはメールの流量制限と通知のキーのwithout_overlapping(): void
    {
        $middleware = (new SendApplicationConfirmedMail(15))->middleware();

        // 流量の制限（中止通知・リマインドと同じリミッター mails）を、ロックより先に
        $this->assertCount(2, $middleware);
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[1]);
        $this->assertSame('application_confirmed:application:15', $middleware[1]->key);
        $this->assertNull($middleware[1]->releaseAfter); // dontRelease(): 重なった 2 本目は戻さずに捨てる
        $this->assertSame(120, $middleware[1]->expiresAfter);
    }

    // ---- レビュー反映（P4-02） ----

    public function test_名前や拠点名のmarkdown記号はリンクや表の列にならない(): void
    {
        Mail::fake();
        $application = $this->confirmedApplication();
        $application->worker->update(['name' => '[花子](https://evil.example)']);
        $application->jobPosting->location->update(['name' => '渋谷|店[x](https://evil.example)']);

        $this->runJob($application->id);

        Mail::assertSent(ApplicationConfirmedMail::class, function (ApplicationConfirmedMail $mail) {
            $html = $mail->render();
            preg_match('#<td[^>]*>拠点</td>\s*<td[^>]*>(.*?)</td>\s*</tr>#s', $html, $row);

            return ! str_contains($html, '<a ')
                && ! str_contains($html, 'href="https://evil.example"')
                // | で列が増えず、拠点名は 1 つのセルにそのまま入る（記号はエスケープされて文字として出る）
                && isset($row[1]) && str_contains($row[1], '渋谷') && str_contains($row[1], '店') && str_contains($row[1], 'evil.example');
        });
    }

    public function test_氏名の改行は空白になり見出しや段落を作らない(): void
    {
        Mail::fake();
        $application = $this->confirmedApplication();
        $application->worker->update(['name' => "山田\n# 偽の見出し"]);

        $this->runJob($application->id);

        Mail::assertSent(ApplicationConfirmedMail::class, function (ApplicationConfirmedMail $mail) {
            $html = $mail->render();

            return str_contains($html, '山田 # 偽の見出し 様') && substr_count($html, '<h1') === 1;
        });
    }

    public function test_同じ通知のジョブが走っている間の2本目は送らずfailedにもならない(): void
    {
        Mail::fake();
        $application = $this->confirmedApplication();
        $job = new SendApplicationConfirmedMail($application->id);
        // 1 本目が実行中＝同じキーのロックを持っている状態を作る
        $lock = Cache::lock($job->middleware()[1]->getLockKey($job), 120);
        $this->assertTrue($lock->get());

        SendApplicationConfirmedMail::dispatch($application->id)->onConnection('database');
        $this->work();

        // 2 本目は戻されずに捨てられる（tries を食わない・failed_jobs に残らない・記録も作らない）
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('notification_logs')->count());

        $lock->release();
    }

    private function confirmedApplication(): Application
    {
        $location = Location::factory()->create(['name' => '渋谷店']);
        $posting = JobPosting::factory()->for($location)->create([
            'job_title' => 'ホールスタッフ',
            'work_date' => '2026-10-10',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'hourly_wage' => 1300,
        ]);
        $worker = Worker::factory()->create(['name' => '山田花子', 'email' => 'hanako@example.com']);

        return Application::factory()->for($posting)->for($worker)->confirmed()->create();
    }

    private function runJob(int $applicationId): void
    {
        (new SendApplicationConfirmedMail($applicationId))->handle(app(NotificationLogService::class));
    }

    /**
     * ワーカーを 1 回だけ回す（make horizon の代わり）。--once は取り出せるジョブがないと --sleep 秒（既定 3 秒）待ってから
     * 終わるので、--sleep=0 で待たずに終わらせる（backoff の待ちの間に回したときなど）
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
