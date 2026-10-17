<?php

namespace Tests\Feature;

use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Enums\ReportExportStatus;
use App\Jobs\FinalizeReportExport;
use App\Jobs\SendApplicationConfirmedMail;
use App\Jobs\SendPostingCancelledMail;
use App\Jobs\SendShiftReminderMail;
use App\Mail\ApplicationConfirmedMail;
use App\Mail\PostingCancelledMail;
use App\Mail\ShiftReminderMail;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\NotificationLog;
use App\Models\ReportExport;
use App\Models\Worker;
use App\Notifications\ReportExportReady;
use App\Services\NotificationLogService;
use App\Services\ReportExportService;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * 同じ通知を二重に送らない（要件 7 章。docs/01 9.1 の必須テスト）。
 * ジョブの handle() を直接呼び、「実行したら何通送られたか」を Mail::fake() で数える。
 * 出力完了は FinalizeReportExport の handle() を直接呼び、Notification::fake() で数える。
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

    // ---- テスト補強（tester） ----

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
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('timeout'));
        try {
            $this->runConfirmedJob($application->id);
        } catch (RuntimeException) {
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

    // ---- F-21 中止通知 ----

    public function test_中止通知ジョブを2回実行してもメールは1通(): void
    {
        Mail::fake();
        $posting = JobPosting::factory()->create(['status' => JobPostingStatus::Cancelled]);
        $application = Application::factory()->for($posting)->cancelled(CancelReason::Company)->create();

        $this->runCancelledJob($application->id);
        $this->runCancelledJob($application->id); // 再試行・手動の再実行・二重投入

        Mail::assertSent(PostingCancelledMail::class, 1);
        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertSame("posting_cancelled:application:{$application->id}", $log->dedupe_key);
    }

    public function test_中止で3人に送った後に同じジョブを積み直しても追加で送らない(): void
    {
        Mail::fake();
        $posting = JobPosting::factory()->create(['capacity' => 3]);
        $ids = Application::factory()->for($posting)->confirmed()->count(3)->create()->pluck('id')->all();

        // sync キューなので、中止のコミット後にリスナが積んだ 3 ジョブがその場で送る（1 人 1 通）
        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();
        foreach ($ids as $id) {
            SendPostingCancelledMail::dispatch($id); // 手動の再投入
        }

        Mail::assertSent(PostingCancelledMail::class, 3);
        $this->assertSame(3, NotificationLog::query()->where('status', NotificationStatus::Sent)->count());
    }

    public function test_同じ応募に確定通知と中止通知はそれぞれ1通ずつ送れる(): void
    {
        // dedupe_key は種類ごとに別（application_confirmed:… と posting_cancelled:…）なので、確定通知の送信済みが中止通知を止めない
        Mail::fake();
        $posting = JobPosting::factory()->create();
        $application = Application::factory()->for($posting)->create();

        $this->actingAsAdmin()->postJson("/api/applications/{$application->id}/confirm")->assertOk();
        $this->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        Mail::assertSent(ApplicationConfirmedMail::class, 1);
        Mail::assertSent(PostingCancelledMail::class, 1);
        $this->assertSame(2, NotificationLog::query()->count());
    }

    // ---- F-22 前日リマインド ----

    public function test_コマンドを2回実行してもリマインドは1通(): void
    {
        // 18 時の実行の後に手で再実行した・スケジューラが 2 回呼んだ、など。sync キューなので積むとその場で送られ、
        // 1 回目のジョブが終わって ShouldBeUnique のロックが外れた後の 2 回目は、notification_logs の送信済み（claim）が止める
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(18, 0));
        $application = Application::factory()->for(JobPosting::factory()->onDate('2026-10-10'))->confirmed()->create();

        $this->artisan('app:send-reminders')->assertExitCode(0);
        $this->artisan('app:send-reminders', ['--date' => '2026-10-10'])->assertExitCode(0);

        Mail::assertSent(ShiftReminderMail::class, 1);
        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertSame("shift_reminder:application:{$application->id}", $log->dedupe_key);
    }

    public function test_リマインドジョブを2回実行しても1通(): void
    {
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(18, 0)); // 勤務日が過ぎていると送らないので「今」を固定する
        $application = Application::factory()->for(JobPosting::factory()->onDate('2026-10-10'))->confirmed()->create();
        $run = fn () => (new SendShiftReminderMail($application->id, '2026-10-10'))->handle(app(NotificationLogService::class));

        $run();
        $run(); // 再試行・手動の再実行・二重投入

        Mail::assertSent(ShiftReminderMail::class, 1);
        $this->assertSame(NotificationStatus::Sent, NotificationLog::query()->sole()->status);
    }

    // ---- F-23 出力完了 ----

    public function test_出力完了通知は2回実行しても1通(): void
    {
        // then の二重実行・手動の再実行。2 回目は出力がもう completed なので連結をやり直さず、claim が送信済みで止める
        Notification::fake();
        Storage::fake('local');
        $export = ReportExport::factory()->processing()->create(['month' => '2026-09', 'user_id' => $this->admin->id]);
        $run = fn () => (new FinalizeReportExport($export->id))->handle(app(ReportExportService::class), app(NotificationLogService::class));

        $run();
        $run();

        Notification::assertSentToTimes($this->admin, ReportExportReady::class, 1);
        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertSame("export_ready:report_export:{$export->id}", $log->dedupe_key);
        $this->assertSame(['user', $this->admin->id], [$log->recipient_type, $log->recipient_id]);
    }

    public function test_出力完了通知の送信に失敗してから再実行するとアプリ内通知も含めて1件ずつ(): void
    {
        Storage::fake('local');
        $export = ReportExport::factory()->processing()->create(['month' => '2026-09', 'user_id' => $this->admin->id]);
        $job = new FinalizeReportExport($export->id);
        $handle = fn () => $job->handle(app(ReportExportService::class), app(NotificationLogService::class));

        // メールのチャネルだけを落とす（database より先に送るので、アプリ内通知も作られない）
        $channels = app(ChannelManager::class);
        $channels->extend('mail', fn () => new class
        {
            public function send(): void
            {
                throw new RuntimeException('SMTP に繋がらない');
            }
        });
        try {
            $handle();
            $this->fail('メールの送信で例外になるはず');
        } catch (RuntimeException $e) {
            $this->assertSame('SMTP に繋がらない', $e->getMessage()); // 再試行に任せる
        }
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(NotificationStatus::Pending, NotificationLog::query()->sole()->status);

        // 復旧した（本物のメールチャネルに戻す。MAIL_MAILER=array）
        $channels->extend('mail', fn ($app) => $app->make(MailChannel::class));
        $channels->forgetDrivers();
        $handle();
        $handle();

        $this->assertSame(1, DB::table('notifications')->count());
        $this->assertSame(NotificationStatus::Sent, NotificationLog::query()->sole()->status);
        $this->assertSame(ReportExportStatus::Completed, $export->fresh()->status);
    }

    private function runCancelledJob(int $applicationId): void
    {
        (new SendPostingCancelledMail($applicationId))->handle(app(NotificationLogService::class));
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
