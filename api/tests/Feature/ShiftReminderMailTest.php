<?php

namespace Tests\Feature;

use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Jobs\SendShiftReminderMail;
use App\Mail\ShiftReminderMail;
use App\Models\Application;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\Worker;
use App\Services\NotificationLogService;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * F-22 前日リマインドのジョブ SendShiftReminderMail（async-rules）。
 *
 * 「実行したらどうなるか」をジョブの handle() / failed() を直接呼んで確かめる（Mail::fake() で送信を記録する）。
 * コマンドが積むかは SendRemindersCommandTest、二重に送らないことは NotificationIdempotencyTest、失敗後の再実行は FailedJobTest。
 *
 * 「今」は 2026-10-09 18:00（Asia/Tokyo）に固定するので、リマインドの対象日（明日）は 2026-10-10。
 */
class ShiftReminderMailTest extends TestCase
{
    private const TOMORROW = '2026-10-10';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(18, 0));
    }

    public function test_ジョブを実行すると勤務の前日リマインドが1通送られる(): void
    {
        Mail::fake();
        $application = $this->reminderApplication('hanako@example.com');

        $this->runJob($application->id);

        Mail::assertSent(ShiftReminderMail::class, 1);
        Mail::assertSent(ShiftReminderMail::class, function (ShiftReminderMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('hanako@example.com')
                && count($mail->to) === 1 && $mail->cc === [] && $mail->bcc === []
                && $mail->hasSubject('【リマインド】10月10日（土）ホールスタッフの勤務のお知らせ')
                && str_contains($html, '山田花子 様')
                && str_contains($html, '2026年10月10日（土） 09:00〜18:00')
                && str_contains($html, '渋谷店')
                && str_contains($html, '東京都渋谷区1-1-1')
                && str_contains($html, 'ホールスタッフ')
                // ワーカーはログインできないので、管理画面にもヘッダーの APP_URL にもリンクしない
                && ! str_contains($html, '<a ')
                && ! str_contains($html, 'http://localhost:3002');
        });

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationKind::ShiftReminder, $log->kind);
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertSame("shift_reminder:application:{$application->id}", $log->dedupe_key);
        $this->assertSame(['application', $application->id], [$log->subject_type, $log->subject_id]);
    }

    public function test_積んだ後にキャンセルされた応募には送らない(): void
    {
        Mail::fake();
        $posting = JobPosting::factory()->onDate(self::TOMORROW)->create();
        $workerCancel = Application::factory()->for($posting)->cancelled(CancelReason::Worker)->create();
        $companyCancel = Application::factory()->for($posting)->cancelled(CancelReason::Company)->create();
        $applied = Application::factory()->for($posting)->create();

        $this->runJob($workerCancel->id);
        $this->runJob($companyCancel->id);
        $this->runJob($applied->id);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_積んだ後に勤務日が変わっていたら送らない(): void
    {
        Mail::fake();
        $application = $this->reminderApplication('hanako@example.com');
        $application->jobPosting->update(['work_date' => '2026-10-12']);

        $this->runJob($application->id); // 積んだときの対象日は 2026-10-10

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_勤務日がすでに過ぎていたら送らない(): void
    {
        // 日をまたいでから failed_jobs を queue:retry した、など。過去の勤務に「当日はよろしく」と送らない
        Mail::fake();
        $application = $this->reminderApplication('hanako@example.com');
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(9, 0));

        $this->runJob($application->id); // 対象日 2026-10-10 は昨日

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_勤務日の当日に実行されたら送る(): void
    {
        // 18 時に積んだ分が流量制限で戻され、日付が変わってから送られることもある。当日ならまだ役に立つので送る
        Mail::fake();
        $application = $this->reminderApplication('hanako@example.com');
        $this->travelTo(now()->setDate(2026, 10, 10)->setTime(0, 30));

        $this->runJob($application->id);

        Mail::assertSent(ShiftReminderMail::class, 1);
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
        $other = Company::factory()->create();
        $theirId = CurrentCompany::runAs($other->id, fn () => $this->reminderApplication('theirs@example.com')->id);

        // 企業を取り違えたジョブ（自社の Context で他社の応募ID）。グローバルスコープで見えないので送らない
        $this->runJob($theirId);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::withoutGlobalScopes()->count()); // 全社の行を数えるため（他社の行も作られていないこと）
    }

    public function test_名前や拠点名のmarkdown記号はリンクや表の列にならない(): void
    {
        Mail::fake();
        $application = $this->reminderApplication('hanako@example.com');
        $application->worker->update(['name' => "[花子](https://evil.example)\n# 偽の見出し"]);
        $application->jobPosting->location->update(['name' => '渋谷|店[x](https://evil.example)']);

        $this->runJob($application->id);

        Mail::assertSent(ShiftReminderMail::class, function (ShiftReminderMail $mail) {
            $html = $mail->render();
            preg_match('#<td[^>]*>拠点</td>\s*<td[^>]*>(.*?)</td>\s*</tr>#s', $html, $row);

            return ! str_contains($html, '<a ')
                && substr_count($html, '<h1') === 1
                && isset($row[1]) && str_contains($row[1], '渋谷') && str_contains($row[1], '店') && str_contains($row[1], 'evil.example');
        });
    }

    public function test_claimの前に打ち切られてもまだ対象ならfailedの記録を作る(): void
    {
        $application = $this->reminderApplication('hanako@example.com');

        (new SendShiftReminderMail($application->id, self::TOMORROW))->failed(new \RuntimeException('期限切れ'));

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertSame(NotificationKind::ShiftReminder, $log->kind);
        $this->assertSame('hanako@example.com', $log->recipient_email);
    }

    public function test_claimの後に失敗したらその行をfailedにする(): void
    {
        $application = $this->reminderApplication('hanako@example.com');
        app(NotificationLogService::class)->claim(NotificationKind::ShiftReminder, $application->worker, $application);

        (new SendShiftReminderMail($application->id, self::TOMORROW))->failed(new \RuntimeException('timeout'));

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertNotNull($log->failed_at);
    }

    public function test_対象でなくなった応募のジョブがfailedになっても記録は作らない(): void
    {
        $application = $this->reminderApplication('hanako@example.com');

        (new SendShiftReminderMail($application->id, '2026-10-11'))->failed(new \RuntimeException('期限切れ'));

        $this->assertSame(0, NotificationLog::query()->count());
    }

    // ---- ジョブの設定 ----

    public function test_ジョブはmailsキューで例外3回か積んでから60分で打ち切りbackoffは10秒と60秒(): void
    {
        $this->freezeSecond();
        $job = new SendShiftReminderMail(1, self::TOMORROW);

        $this->assertSame('mails', $job->queue);
        $this->assertSame(0, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertEquals(now()->addMinutes(60), $job->retryUntil());
        $this->assertSame([10, 60], $job->backoff());
    }

    public function test_ミドルウェアは通知のキーのwithout_overlappingとメールの流量制限(): void
    {
        $middleware = (new SendShiftReminderMail(15, self::TOMORROW))->middleware();

        $this->assertCount(2, $middleware);
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[1]);
        $this->assertSame('shift_reminder:application:15', $middleware[1]->key);
        $this->assertNull($middleware[1]->releaseAfter);
        $this->assertSame(120, $middleware[1]->expiresAfter);
    }

    public function test_重複防止のキーは応募idで再試行の期限まで有効(): void
    {
        $job = new SendShiftReminderMail(15, self::TOMORROW);

        $this->assertSame('15', (string) $job->uniqueId());
        $this->assertSame(60 * 60, $job->uniqueFor());
    }

    public function test_ジョブが終わると重複防止のロックが外れる(): void
    {
        // sync キューなので積むとその場で実行され、終わったらロックが外れる（翌日以降の再実行や手動の投入を妨げない）
        Mail::fake();
        $application = $this->reminderApplication('hanako@example.com');

        SendShiftReminderMail::dispatch($application->id, self::TOMORROW);

        Mail::assertSent(ShiftReminderMail::class, 1);
        $lock = app(Cache::class)->lock('laravel_unique_job:'.SendShiftReminderMail::class.':'.$application->id, 10);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    private function reminderApplication(string $email): Application
    {
        $location = Location::factory()->create(['name' => '渋谷店', 'address' => '東京都渋谷区1-1-1']);
        $posting = JobPosting::factory()->for($location)->create([
            'job_title' => 'ホールスタッフ',
            'work_date' => self::TOMORROW,
            'start_time' => '09:00',
            'end_time' => '18:00',
            'status' => JobPostingStatus::Open,
        ]);
        $worker = Worker::factory()->create(['name' => '山田花子', 'email' => $email]);

        return Application::factory()->for($posting)->for($worker)->confirmed()->create();
    }

    private function runJob(int $applicationId, string $workDate = self::TOMORROW): void
    {
        (new SendShiftReminderMail($applicationId, $workDate))->handle(app(NotificationLogService::class));
    }
}
