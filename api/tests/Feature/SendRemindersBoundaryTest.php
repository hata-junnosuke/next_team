<?php

namespace Tests\Feature;

use App\Jobs\SendPostingCancelledMail;
use App\Jobs\SendShiftReminderMail;
use App\Mail\ShiftReminderMail;
use App\Models\Application;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\NotificationLog;
use App\Models\Worker;
use App\Services\NotificationLogService;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * F-22 前日リマインドの境界（P4-04 のテスト補強。SendRemindersCommandTest の続き）。
 *
 * - 「翌日」は Asia/Tokyo の日付で決まる（UTC ではない）。0 時の前後・月末・年末・うるう年
 * - 締切の募集の確定応募には送る／中止した募集（確定は企業都合キャンセルになる）には送らない
 * - 無効ワーカーでも既存の確定には送る（F-03「既存の確定は影響を受けない」）
 * - ShouldBeUnique のロックが残ったとき（ワーカーが落ちた等）の挙動と、期限後の積み直し
 * - スケジューラが Asia/Tokyo の設定時刻に動くこと
 *
 * 「積まれたか」は Queue::fake()、「実行したらどうなるか」は handle() 直呼びで、1 本に混ぜない。
 */
class SendRemindersBoundaryTest extends TestCase
{
    // ---- 「翌日」の境界（Asia/Tokyo） ----

    /**
     * @return array<string, array{string, string}>
     */
    public static function boundaryProvider(): array
    {
        // [UTC で表した「今」, 対象になるべき勤務日（Asia/Tokyo の翌日）]
        return [
            '東京の23時59分59秒は翌日が10月10日' => ['2026-10-09 14:59:59', '2026-10-10'],
            '東京の0時ちょうどは日付が進み翌日が10月11日' => ['2026-10-09 15:00:00', '2026-10-11'],
            'UTCではまだ前日の東京0時30分' => ['2026-10-09 15:30:00', '2026-10-11'],
            '月末の10月31日は翌日が11月1日' => ['2026-10-31 09:00:00', '2026-11-01'],
            '年末の12月31日は翌日が翌年1月1日' => ['2026-12-31 09:00:00', '2027-01-01'],
            '年末の東京0時は翌年1月2日' => ['2026-12-31 15:00:00', '2027-01-02'],
            'うるう年の2月28日は翌日が2月29日' => ['2028-02-28 09:00:00', '2028-02-29'],
            '平年の2月28日は翌日が3月1日' => ['2027-02-28 09:00:00', '2027-03-01'],
        ];
    }

    #[DataProvider('boundaryProvider')]
    public function test_翌日はasia_tokyoの日付で決まる(string $nowUtc, string $expected): void
    {
        $this->travelTo(CarbonImmutable::parse($nowUtc, 'UTC'));
        Queue::fake();
        $expectedDay = CarbonImmutable::parse($expected);
        $target = Application::factory()->for(JobPosting::factory()->onDate($expected))->confirmed()->create();
        // 前後の日の確定応募には積まない（UTC で計算すると 1 日ずれてこちらに積まれる）
        Application::factory()->for(JobPosting::factory()->onDate($expectedDay->subDay()->toDateString()))->confirmed()->create();
        Application::factory()->for(JobPosting::factory()->onDate($expectedDay->addDay()->toDateString()))->confirmed()->create();
        Context::forget('company_id');

        $this->artisan('app:send-reminders')->assertExitCode(0);

        Queue::assertCount(1);
        Queue::assertPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $target->id
            && $job->workDate === $expected);
    }

    // ---- 募集の状態・ワーカーの状態 ----

    public function test_締切の募集の確定応募には積まれる(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00'));
        Queue::fake();
        $application = Application::factory()->for(JobPosting::factory()->onDate('2026-10-10')->closed())->confirmed()->create();

        $this->artisan('app:send-reminders')->assertExitCode(0);

        Queue::assertCount(1);
        Queue::assertPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $application->id);
    }

    public function test_中止した募集の確定だった応募には積まれない(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00'));
        Queue::fake();
        $posting = JobPosting::factory()->onDate('2026-10-10')->create(['capacity' => 2]);
        Application::factory()->for($posting)->confirmed()->count(2)->create();
        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        $this->artisan('app:send-reminders')->assertExitCode(0);

        Queue::assertPushed(SendPostingCancelledMail::class, 2); // 中止通知は積まれる（F-21）
        Queue::assertNotPushed(SendShiftReminderMail::class);
    }

    public function test_リマインドを積んだ後に募集が中止されたらジョブは送らない(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00'));
        Mail::fake();
        $posting = JobPosting::factory()->onDate('2026-10-10')->create();
        $application = Application::factory()->for($posting)->confirmed()->create();
        $job = new SendShiftReminderMail($application->id, '2026-10-10'); // コマンドが積んだ状態
        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();
        Mail::fake(); // 中止通知（sync で送られる）を数えないよう数え直す

        $job->handle(app(NotificationLogService::class));

        Mail::assertNotSent(ShiftReminderMail::class);
        $this->assertSame(0, NotificationLog::query()->where('kind', 'shift_reminder')->count());
    }

    public function test_無効ワーカーでも既存の確定には積まれる(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00'));
        Queue::fake();
        $worker = Worker::factory()->inactive()->create();
        $application = Application::factory()->for(JobPosting::factory()->onDate('2026-10-10'))->for($worker)->confirmed()->create();

        $this->artisan('app:send-reminders')->assertExitCode(0);

        Queue::assertPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $application->id);
    }

    public function test_確定後に無効にしたワーカーにもリマインドが送られる(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00'));
        Mail::fake();
        $worker = Worker::factory()->create(['email' => 'inactive@example.com']);
        $application = Application::factory()->for(JobPosting::factory()->onDate('2026-10-10'))->for($worker)->confirmed()->create();
        $this->actingAsAdmin()->patchJson("/api/workers/{$worker->id}/active", ['is_active' => false])->assertOk();
        $this->assertFalse($worker->fresh()->is_active);

        (new SendShiftReminderMail($application->id, '2026-10-10'))->handle(app(NotificationLogService::class));

        Mail::assertSent(ShiftReminderMail::class, fn (ShiftReminderMail $mail) => $mail->hasTo('inactive@example.com'));
    }

    // ---- 複数の企業と Context ----

    public function test_3社のうち応募のない企業があっても各社の企業idがペイロードのcontextに入る(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00'));
        config(['queue.default' => 'database']);
        $mineIds = Application::factory()->for(JobPosting::factory()->onDate('2026-10-10')->state(['capacity' => 2]))
            ->confirmed()->count(2)->create()->pluck('id')->all();
        $empty = Company::factory()->create(); // 確定応募のない企業
        $third = Company::factory()->create();
        $thirdId = CurrentCompany::runAs($third->id, fn () => Application::factory()
            ->for(JobPosting::factory()->onDate('2026-10-10'))->confirmed()->create()->id);

        // 企業が設定されたまま呼ばれても（リクエストの中など）、runAs が企業ごとに切り替え、終わったら元に戻す
        $this->artisan('app:send-reminders')->assertExitCode(0);

        $this->assertSame($this->company->id, CurrentCompany::id());
        $companies = DB::table('jobs')->where('queue', 'mails')->pluck('payload')
            ->mapWithKeys(function (string $payload) {
                $decoded = json_decode($payload, true);

                return [unserialize($decoded['data']['command'])->applicationId => unserialize($decoded['illuminate:log:context']['data']['company_id'])];
            })->all();
        $expected = [$mineIds[0] => $this->company->id, $mineIds[1] => $this->company->id, $thirdId => $third->id];
        ksort($companies);
        ksort($expected);
        $this->assertSame($expected, $companies);
        $this->assertNotContains($empty->id, $companies);
    }

    // ---- ShouldBeUnique のロックが残ったとき ----

    public function test_重複防止のロックが残っているとその応募だけ積まれず期限を過ぎると積み直せる(): void
    {
        // ワーカーが落ちてジョブが消え、ロックだけが残った状態を作る（uniqueFor = 60 分）
        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00'));
        Queue::fake();
        $posting = JobPosting::factory()->onDate('2026-10-10')->create(['capacity' => 2]);
        [$stuck, $other] = Application::factory()->for($posting)->confirmed()->count(2)->create()->all();
        $this->assertTrue(app(Cache::class)->lock($this->uniqueLockKey($stuck->id), 60 * 60)->get());

        $this->artisan('app:send-reminders')->assertExitCode(0);

        Queue::assertCount(1); // 他の応募は止まらない
        Queue::assertPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $other->id);

        // 期限（60 分）を過ぎて手で再実行すると、残っていた応募のジョブも積まれる
        $this->travel(61)->minutes();
        $this->artisan('app:send-reminders', ['--date' => '2026-10-10'])->assertExitCode(0);

        Queue::assertPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $stuck->id);
    }

    public function test_送信済みの後にロックが外れて積み直されても2通目は送らない(): void
    {
        // 1 段目・2 段目（キャッシュのロック）が外れても、3 段目（notification_logs の UNIQUE）が止める
        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00'));
        Mail::fake();
        $application = Application::factory()->for(JobPosting::factory()->onDate('2026-10-10'))->confirmed()->create();

        $this->artisan('app:send-reminders')->assertExitCode(0); // sync なのでその場で送られる
        app(Cache::class)->lock($this->uniqueLockKey($application->id))->forceRelease();
        $this->travel(2)->hours();
        $this->artisan('app:send-reminders', ['--date' => '2026-10-10'])->assertExitCode(0);

        Mail::assertSent(ShiftReminderMail::class, 1);
        $this->assertSame(1, NotificationLog::query()->count());
    }

    // ---- --date の不正値 ----

    public function test_dateオプションの不正値は理由を出して失敗し何も積まない(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00'));
        Queue::fake();
        Application::factory()->for(JobPosting::factory()->onDate('2026-10-10'))->confirmed()->create();

        foreach (['', '2026-02-29', '2026-04-31', '20261010', '2026-10-1', ' 2026-10-10', '2026-10-10 ', '2026-00-10', '0000-00-00'] as $invalid) {
            $this->artisan('app:send-reminders', ['--date' => $invalid])
                ->expectsOutputToContain('--date は Y-m-d 形式の日付で指定してください')
                ->assertExitCode(1);
        }

        Queue::assertNothingPushed();
    }

    public function test_dateオプションでうるう日を指定できる(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-02-20 18:00'));
        Queue::fake();
        $application = Application::factory()->for(JobPosting::factory()->onDate('2028-02-29'))->confirmed()->create();

        $this->artisan('app:send-reminders', ['--date' => '2028-02-29'])->assertExitCode(0);

        Queue::assertPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $application->id
            && $job->workDate === '2028-02-29');
    }

    // ---- スケジューラ ----

    public function test_スケジューラはasia_tokyoの設定時刻にだけ実行される(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'app:send-reminders'));
        $this->assertNotNull($event);
        $time = CarbonImmutable::parse('2026-10-31 '.config('myteam.reminder_time'), 'Asia/Tokyo');

        $this->travelTo($time->utc()); // 東京の設定時刻（UTC では 9 時間前）
        $this->assertTrue($event->isDue($this->app));

        $this->travelTo($time->subMinute()->utc());
        $this->assertFalse($event->isDue($this->app));

        // UTC で解釈されていれば、ここ（UTC の設定時刻 = 東京の 9 時間後）で動いてしまう
        $this->travelTo(CarbonImmutable::parse('2026-10-31 '.config('myteam.reminder_time'), 'UTC'));
        $this->assertFalse($event->isDue($this->app));

        // 月末・年末をまたいでも毎日動く
        $this->travelTo(CarbonImmutable::parse('2026-12-31 '.config('myteam.reminder_time'), 'Asia/Tokyo')->utc());
        $this->assertTrue($event->isDue($this->app));
    }

    private function uniqueLockKey(int $applicationId): string
    {
        return 'laravel_unique_job:'.SendShiftReminderMail::class.':'.$applicationId;
    }
}
