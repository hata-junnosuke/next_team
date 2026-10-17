<?php

namespace Tests\Feature;

use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Enums\NotificationStatus;
use App\Jobs\SendShiftReminderMail;
use App\Mail\ShiftReminderMail;
use App\Models\Application;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\Worker;
use App\Support\CurrentCompany;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * F-22 前日リマインド（docs/01 の非同期の契約、async-rules「スケジューラ」）。
 *
 * - コマンド（app:send-reminders）は「積むだけ」。積まれたかを Queue::fake() で確かめる
 * - 企業ごとに Context が設定されることは、database キューに積んでワーカー（queue:work --once）で実行して確かめる
 * ジョブ（SendShiftReminderMail）の「実行したらどうなるか」は ShiftReminderMailTest、二重に送らないことは NotificationIdempotencyTest、
 * 失敗後の再実行は FailedJobTest。
 *
 * 「今」は 2026-10-09 18:00（Asia/Tokyo）に固定するので、既定の対象日（明日）は 2026-10-10。
 */
class SendRemindersCommandTest extends TestCase
{
    private const TOMORROW = '2026-10-10';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(18, 0));
    }

    // ---- コマンドが積むか（Queue::fake） ----

    public function test_翌日勤務の確定応募ごとにジョブが積まれる(): void
    {
        Queue::fake();
        Mail::fake();
        $posting = JobPosting::factory()->onDate(self::TOMORROW)->create(['capacity' => 2]);
        $ids = Application::factory()->for($posting)->confirmed()->count(2)->create()->pluck('id')->all();
        Context::forget('company_id'); // スケジューラから呼ばれたときと同じく、企業が決まっていない状態で始める

        $this->artisan('app:send-reminders')->assertExitCode(0);

        Queue::assertCount(2);
        Queue::assertPushedOn('mails', SendShiftReminderMail::class);
        foreach ($ids as $id) {
            Queue::assertPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $id
                && $job->workDate === self::TOMORROW);
        }
        Mail::assertNothingSent(); // コマンドの中でメールを送らない
        $this->assertFalse(CurrentCompany::check()); // runAs の後は元の「企業なし」に戻る
    }

    public function test_応募中やキャンセルの応募には積まれない(): void
    {
        Queue::fake();
        $posting = JobPosting::factory()->onDate(self::TOMORROW)->create(['capacity' => 5]);
        $confirmed = Application::factory()->for($posting)->confirmed()->create();
        Application::factory()->for($posting)->create();
        Application::factory()->for($posting)->rejected()->create();
        Application::factory()->for($posting)->cancelled(CancelReason::Worker)->create();
        Application::factory()->for($posting)->cancelled(CancelReason::Company)->create();

        $this->artisan('app:send-reminders')->assertExitCode(0);

        Queue::assertCount(1);
        Queue::assertPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $confirmed->id);
    }

    public function test_翌日以外の勤務日の確定応募には積まれない(): void
    {
        Queue::fake();
        foreach (['2026-10-09', '2026-10-11', '2026-10-08'] as $date) {
            Application::factory()->for(JobPosting::factory()->onDate($date))->confirmed()->create();
        }

        $this->artisan('app:send-reminders')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_dateオプションで対象日を指定できる(): void
    {
        Queue::fake();
        $tomorrow = Application::factory()->for(JobPosting::factory()->onDate(self::TOMORROW))->confirmed()->create();
        $target = Application::factory()->for(JobPosting::factory()->onDate('2026-10-20'))->confirmed()->create();

        $this->artisan('app:send-reminders', ['--date' => '2026-10-20'])->assertExitCode(0);

        Queue::assertCount(1);
        Queue::assertPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $target->id
            && $job->workDate === '2026-10-20');
        Queue::assertNotPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $tomorrow->id);
    }

    public function test_dateオプションが日付でなければ失敗して何も積まない(): void
    {
        Queue::fake();
        Application::factory()->for(JobPosting::factory()->onDate(self::TOMORROW))->confirmed()->create();

        foreach (['tomorrow', '2026-13-01', '2026/10/10', '2026-10-10 00:00'] as $invalid) {
            $this->artisan('app:send-reminders', ['--date' => $invalid])->assertExitCode(1);
        }

        Queue::assertNothingPushed();
    }

    public function test_複数の企業それぞれの応募に積まれる(): void
    {
        Queue::fake();
        $mine = Application::factory()->for(JobPosting::factory()->onDate(self::TOMORROW))->confirmed()->create();
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Application::factory()
            ->for(JobPosting::factory()->onDate(self::TOMORROW))->confirmed()->create());
        Context::forget('company_id');

        $this->artisan('app:send-reminders')->assertExitCode(0);

        Queue::assertCount(2);
        foreach ([$mine->id, $theirs->id] as $id) {
            Queue::assertPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $id);
        }
    }

    public function test_コマンドを2回実行しても同じ応募のジョブは1つしか積まれない(): void
    {
        // ShouldBeUnique（uniqueId = 応募ID）: 積む前にキャッシュのロックを取るので、2 回目は積まない
        Queue::fake();
        $application = Application::factory()->for(JobPosting::factory()->onDate(self::TOMORROW))->confirmed()->create();

        $this->artisan('app:send-reminders')->assertExitCode(0);
        $this->artisan('app:send-reminders')->assertExitCode(0);

        Queue::assertCount(1);
        Queue::assertPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $application->id);
    }

    // ---- 1 社の失敗で他社を止めない ----

    public function test_1社目で例外が出ても2社目は積まれ終了コードは失敗になる(): void
    {
        Queue::fake();
        $reported = $this->collectReported();
        $mine = Application::factory()->for(JobPosting::factory()->onDate(self::TOMORROW))->confirmed()->create();
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Application::factory()
            ->for(JobPosting::factory()->onDate(self::TOMORROW))->confirmed()->create());
        Context::forget('company_id');
        $this->failApplicationsQueryFor($this->company->id); // 1 社目（ID が小さい自社）の応募の検索だけ失敗させる

        $this->artisan('app:send-reminders')->assertExitCode(1);

        Queue::assertCount(1);
        Queue::assertPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $theirs->id);
        Queue::assertNotPushed(SendShiftReminderMail::class, fn (SendShiftReminderMail $job) => $job->applicationId === $mine->id);
        $this->assertCount(1, $reported); // 握りつぶさずに報告（ログ）に残す
        $this->assertInstanceOf(\RuntimeException::class, $reported[0]);
    }

    public function test_例外の後も企業の設定は残らない(): void
    {
        Queue::fake();
        $this->collectReported();
        Application::factory()->for(JobPosting::factory()->onDate(self::TOMORROW))->confirmed()->create();
        Context::forget('company_id');
        $this->failApplicationsQueryFor($this->company->id);

        $this->artisan('app:send-reminders')->assertExitCode(1);

        $this->assertFalse(CurrentCompany::check()); // runAs の finally で元の「企業なし」に戻る
    }

    // ---- 企業ごとの Context（database キュー + ワーカー） ----

    public function test_企業ごとにcontextが設定され他社の応募と混ざらずそれぞれの宛先に1通ずつ届く(): void
    {
        Mail::fake();
        config(['queue.default' => 'database']);
        $mine = $this->reminderApplication('mine@example.com');
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => $this->reminderApplication('theirs@example.com'));
        Context::forget('company_id');

        $this->artisan('app:send-reminders')->assertExitCode(0);

        // 積んだときの企業がペイロードの Context に入っている（ワーカーはこれを戻して動く）
        $companies = DB::table('jobs')->where('queue', 'mails')->pluck('payload')
            ->mapWithKeys(fn (string $payload) => $this->payloadCompany($payload))->all();
        $this->assertEquals([$mine->id => $this->company->id, $theirs->id => $other->id], $companies);

        foreach ([1, 2] as $_) {
            Context::forget('company_id');
            $this->work();
        }

        Mail::assertSent(ShiftReminderMail::class, 2);
        Mail::assertSent(ShiftReminderMail::class, fn (ShiftReminderMail $mail) => $mail->hasTo('mine@example.com') && count($mail->to) === 1);
        Mail::assertSent(ShiftReminderMail::class, fn (ShiftReminderMail $mail) => $mail->hasTo('theirs@example.com') && count($mail->to) === 1);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        // 記録はスコープを通さずに読む（ワーカーが戻した企業のまま終わるので、モデル経由だと片方しか見えない）
        $logs = DB::table('notification_logs')->get()->keyBy('subject_id');
        $this->assertSame($this->company->id, (int) $logs[$mine->id]->company_id);
        $this->assertSame($other->id, (int) $logs[$theirs->id]->company_id);
        $this->assertSame(NotificationStatus::Sent->value, $logs[$mine->id]->status);
        $this->assertSame(NotificationStatus::Sent->value, $logs[$theirs->id]->status);
    }

    // ---- スケジューラ ----

    public function test_スケジューラは設定の時刻に毎日重ならないように実行する(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, 'app:send-reminders'));

        $this->assertCount(1, $events);
        $event = $events->first();
        [$hour, $minute] = array_map('intval', explode(':', (string) config('myteam.reminder_time')));
        $this->assertSame("{$minute} {$hour} * * *", $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(30, $event->expiresAt); // 重なり防止のロックは 30 分で外れる（落ちた実行のロックで翌日以降が止まらない）
    }

    /**
     * 報告（report()）された例外を集める
     *
     * @return \ArrayObject<int, \Throwable>
     */
    private function collectReported(): \ArrayObject
    {
        $reported = new \ArrayObject;
        $this->app->make(ExceptionHandler::class)->reportable(function (\Throwable $e) use ($reported) {
            $reported[] = $e;
        });

        return $reported;
    }

    /**
     * 指定した企業として応募を検索したときだけ例外にする（DB の障害の代わり。クエリの実行後に呼ばれるリスナから投げる）
     */
    private function failApplicationsQueryFor(int $companyId): void
    {
        DB::listen(function (QueryExecuted $query) use ($companyId) {
            if (str_contains($query->sql, 'from "applications"') && CurrentCompany::check() && CurrentCompany::id() === $companyId) {
                throw new \RuntimeException('応募の検索に失敗しました');
            }
        });
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

    /**
     * ペイロードから [応募ID => 積んだときの企業ID] を取り出す（Context は illuminate:log:context に直列化されて入る）
     *
     * @return array<int, int>
     */
    private function payloadCompany(string $payload): array
    {
        $decoded = json_decode($payload, true);
        $job = unserialize($decoded['data']['command']);
        $companyId = unserialize($decoded['illuminate:log:context']['data']['company_id']);

        return [$job->applicationId => $companyId];
    }

    /**
     * ワーカーを 1 回だけ回す（make horizon の代わり）
     */
    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'mails', '--once' => true, '--sleep' => 0])->assertExitCode(0);
    }
}
