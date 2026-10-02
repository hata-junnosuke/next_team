<?php

namespace Tests\Feature;

use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Jobs\SendApplicationConfirmedMail;
use App\Mail\ApplicationConfirmedMail;
use App\Models\Application;
use App\Models\NotificationLog;
use App\Models\Worker;
use App\Services\NotificationLogService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 同じ通知を二重に送らない（要件 7 章「4.7 を実装する弾のみ」、docs/03 4 章。docs/01 9.1 の必須テスト）。
 * ジョブの handle() を直接呼び、「実行したら何通送られたか」を Mail::fake() で数える。
 * 中止通知（P4-03）・前日リマインド（P4-04）・出力完了（P4-05）はそれぞれのタスクで追記する。
 */
class NotificationIdempotencyTest extends TestCase
{
    // ---- F-20 確定通知 ----

    public function test_確定通知ジョブを2回実行してもメールは1通(): void
    {
        Mail::fake();
        $application = Application::factory()->confirmed()->create();

        $this->runConfirmedJob($application->id);
        $this->runConfirmedJob($application->id); // 再試行・手動の再実行・二重投入

        Mail::assertSentCount(1);
        $this->assertSame(1, NotificationLog::query()->count());
    }

    public function test_送信後にnotification_logsがsentになる(): void
    {
        Mail::fake();
        $worker = Worker::factory()->create(['email' => 'taro@example.com']);
        $application = Application::factory()->for($worker)->confirmed()->create();

        $this->runConfirmedJob($application->id);

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertNotNull($log->sent_at);
        $this->assertNull($log->error);
        $this->assertSame(NotificationKind::ApplicationConfirmed, $log->kind);
        $this->assertSame("application_confirmed:application:{$application->id}", $log->dedupe_key);
        $this->assertSame('taro@example.com', $log->recipient_email);
        $this->assertSame(['worker', $worker->id], [$log->recipient_type, $log->recipient_id]);
        $this->assertSame(['application', $application->id], [$log->subject_type, $log->subject_id]);
        $this->assertSame($this->company->id, $log->company_id);
    }

    public function test_同じ確定通知ジョブを2回積んでもメールは1通(): void
    {
        Mail::fake();
        $application = Application::factory()->confirmed()->create();

        // phpunit.xml は QUEUE_CONNECTION=sync なので、積むとその場で実行される
        SendApplicationConfirmedMail::dispatch($application->id);
        SendApplicationConfirmedMail::dispatch($application->id);

        Mail::assertSent(ApplicationConfirmedMail::class, 1);
    }

    public function test_失敗の記録が残っていれば再実行で送りsentになる(): void
    {
        Mail::fake();
        $application = Application::factory()->confirmed()->create();
        $logs = app(NotificationLogService::class);
        $log = $logs->claim(NotificationKind::ApplicationConfirmed, $application->worker, $application);
        $logs->markFailed($log, null);

        $this->runConfirmedJob($application->id);

        Mail::assertSentCount(1);
        $this->assertSame(NotificationStatus::Sent, $log->fresh()->status);
    }

    // ---- テスト補強（P4-02 tester） ----

    public function test_承認apiで積まれたジョブの後に同じジョブを手で積んでもメールは1通(): void
    {
        Mail::fake();
        $application = Application::factory()->create();

        // sync キューなので、承認のコミット後にリスナが積んだジョブがその場で送る（1 通目）
        $this->actingAsAdmin()->postJson("/api/applications/{$application->id}/confirm")->assertOk();
        SendApplicationConfirmedMail::dispatch($application->id); // 手動の再投入

        Mail::assertSent(ApplicationConfirmedMail::class, 1);
        $this->assertSame(NotificationStatus::Sent, NotificationLog::query()->sole()->status);
    }

    public function test_送信済みのジョブを再実行しても2通目を送らずsent_atは最初のまま(): void
    {
        Mail::fake();
        $application = Application::factory()->confirmed()->create();
        $this->runConfirmedJob($application->id);
        $sentAt = NotificationLog::query()->sole()->sent_at;

        $this->travel(5)->minutes();
        $this->runConfirmedJob($application->id);

        Mail::assertSentCount(1);
        $this->assertEquals($sentAt, NotificationLog::query()->sole()->sent_at);
    }

    public function test_送信中に失敗してから再実行すると合わせて1通でsentになる(): void
    {
        $application = Application::factory()->confirmed()->create();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('timeout'));
        try {
            $this->runConfirmedJob($application->id);
        } catch (\RuntimeException) {
            // 1 回目の試行は失敗（記録は pending のまま）
        }

        $this->stopFailingMailer();
        Mail::fake();
        $this->runConfirmedJob($application->id); // 2 回目の試行
        $this->runConfirmedJob($application->id); // さらに再実行されても送らない

        Mail::assertSentCount(1);
        $this->assertSame(1, NotificationLog::query()->count());
        $this->assertSame(NotificationStatus::Sent, NotificationLog::query()->sole()->status);
    }

    private function runConfirmedJob(int $applicationId): void
    {
        (new SendApplicationConfirmedMail($applicationId))->handle(app(NotificationLogService::class));
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
