<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Exceptions\DomainException;
use App\Models\Application;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\Worker;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ドメイン基盤（docs/01 の業務テーブル・SQLite の設定・モデル）。並列担当（A / B / C）が前提にするものを固定する。
 * - モデルの既定値・cast、transitionTo() の基本動作（遷移表そのものは tests/Unit/ApplicationStatusTest.php）
 * - ファクトリの状態（confirmed() が時給・交通費をコピーする、など）
 * - DB の制約（UNIQUE・restrictOnDelete）と SQLite の設定
 */
class DomainFoundationTest extends TestCase
{
    public function test_作成直後の募集と応募とワーカーは既定の状態を持つ(): void
    {
        $location = Location::factory()->create();
        $posting = JobPosting::create([
            'location_id' => $location->id, 'job_title' => '品出し', 'work_date' => '2026-10-10',
            'start_time' => '09:00', 'end_time' => '17:00', 'capacity' => 1, 'hourly_wage' => 1300,
        ]);
        $worker = Worker::create(['name' => '山田 花子', 'email' => 'hanako@example.com']);
        $application = Application::create(['job_posting_id' => $posting->id, 'worker_id' => $worker->id]);

        $this->assertSame(JobPostingStatus::Open, $posting->status);
        $this->assertSame(0, $posting->transport_allowance);
        $this->assertTrue($worker->is_active);
        $this->assertSame(ApplicationStatus::Applied, $application->status);
        $this->assertSame($this->company->id, $application->company_id);
    }

    public function test_statusとcompany_idはfillableではない(): void
    {
        foreach ([new JobPosting, new Application, new Worker, new Attendance] as $model) {
            $this->assertFalse($model->isFillable('company_id'), $model::class);
            $this->assertFalse($model->isFillable('status'), $model::class);
        }
    }

    public function test_transition_toは状態と追加の列を保存する(): void
    {
        $application = Application::factory()->create();

        $application->transitionTo(ApplicationStatus::Confirmed, [
            'confirmed_at' => now(), 'confirmed_hourly_wage' => 1300, 'confirmed_transport_allowance' => 500,
        ]);

        $fresh = $application->fresh();
        $this->assertSame(ApplicationStatus::Confirmed, $fresh->status);
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertSame(1300, $fresh->confirmed_hourly_wage);
        $this->assertSame(500, $fresh->confirmed_transport_allowance);
    }

    public function test_transition_toは古いインスタンスでもdbの最新の状態で判定する(): void
    {
        // 同時に 2 つの操作が来た場面: 同じ応募を別々に読み込み、先に却下された後で、古い方から承認しようとする
        $application = Application::factory()->create();
        $stale = Application::query()->findOrFail($application->id);
        $application->transitionTo(ApplicationStatus::Rejected);

        try {
            $stale->transitionTo(ApplicationStatus::Confirmed, [
                'confirmed_at' => now(), 'confirmed_hourly_wage' => 1300, 'confirmed_transport_allowance' => 500,
            ]);
            $this->fail('DomainException が投げられていない');
        } catch (DomainException $e) {
            $this->assertSame('却下の応募は確定にできません。', $e->getMessage());
        }

        $this->assertSame(ApplicationStatus::Rejected, $application->fresh()->status);
        $this->assertNull($application->fresh()->confirmed_at);

        // 募集も同じ: 先に中止された募集を、古いインスタンスから締め切れない
        $posting = JobPosting::factory()->create();
        $stalePosting = JobPosting::query()->findOrFail($posting->id);
        $posting->transitionTo(JobPostingStatus::Cancelled);

        $this->expectException(DomainException::class);
        $stalePosting->transitionTo(JobPostingStatus::Closed);
    }

    public function test_transition_toは状態に付随する列が欠けているとlogic_exception(): void
    {
        $cases = [
            '確定で時給がない' => [ApplicationStatus::Confirmed, ['confirmed_at' => now(), 'confirmed_transport_allowance' => 500]],
            '確定で確定日時がない' => [ApplicationStatus::Confirmed, ['confirmed_hourly_wage' => 1300, 'confirmed_transport_allowance' => 500]],
            'キャンセルで理由がない' => [ApplicationStatus::Cancelled, ['cancelled_at' => now()]],
            'キャンセルで日時がない' => [ApplicationStatus::Cancelled, ['cancel_reason' => CancelReason::Worker]],
        ];

        foreach ($cases as $label => [$next, $attributes]) {
            $application = $next === ApplicationStatus::Cancelled
                ? Application::factory()->confirmed()->create()
                : Application::factory()->create();
            $before = $application->status;

            try {
                $application->transitionTo($next, $attributes);
                $this->fail("{$label}: LogicException が投げられていない");
            } catch (LogicException) {
                $this->assertSame($before, $application->fresh()->status, $label);
            }
        }
    }

    public function test_transition_toは状態に付随しない列を受け付けない(): void
    {
        $cases = [
            '却下に確定日時' => fn () => Application::factory()->create()
                ->transitionTo(ApplicationStatus::Rejected, ['confirmed_at' => now()]),
            '確定に status' => fn () => Application::factory()->create()->transitionTo(ApplicationStatus::Confirmed, [
                'confirmed_at' => now(), 'confirmed_hourly_wage' => 1300, 'confirmed_transport_allowance' => 500, 'status' => 'rejected',
            ]),
            'キャンセルに worker_id' => fn () => Application::factory()->confirmed()->create()->transitionTo(ApplicationStatus::Cancelled, [
                'cancel_reason' => CancelReason::Worker, 'cancelled_at' => now(), 'worker_id' => 1,
            ]),
            '募集の締切に capacity' => fn () => JobPosting::factory()->create()
                ->transitionTo(JobPostingStatus::Closed, ['capacity' => 100]),
        ];

        foreach ($cases as $label => $transition) {
            try {
                $transition();
                $this->fail("{$label}: LogicException が投げられていない");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_transition_toは遷移できなければdomain_exceptionで保存しない(): void
    {
        $posting = JobPosting::factory()->cancelled()->create();

        try {
            $posting->transitionTo(JobPostingStatus::Closed);
            $this->fail('DomainException が投げられていない');
        } catch (DomainException $e) {
            $this->assertSame('中止の募集は締切にできません。', $e->getMessage());
        }

        $this->assertSame(JobPostingStatus::Cancelled, $posting->fresh()->status);
    }

    public function test_確定のファクトリは募集の時給と交通費と確定日時を埋める(): void
    {
        $posting = JobPosting::factory()->create(['hourly_wage' => 1500, 'transport_allowance' => 300]);

        $application = Application::factory()->for($posting)->confirmed()->create();

        $this->assertSame(ApplicationStatus::Confirmed, $application->status);
        $this->assertSame(1500, $application->confirmed_hourly_wage);
        $this->assertSame(300, $application->confirmed_transport_allowance);
        $this->assertNotNull($application->confirmed_at);
    }

    public function test_キャンセルのファクトリは理由区分とキャンセル日時を埋める(): void
    {
        $application = Application::factory()->cancelled(CancelReason::Company)->create();

        $this->assertSame(ApplicationStatus::Cancelled, $application->status);
        $this->assertSame(CancelReason::Company, $application->cancel_reason);
        $this->assertNotNull($application->cancelled_at);
        $this->assertSame(1300, $application->confirmed_hourly_wage);
    }

    public function test_勤怠のファクトリは確定済みの応募に募集どおりの時間と報酬で作られる(): void
    {
        $attendance = Attendance::factory()->create();

        $this->assertSame(ApplicationStatus::Confirmed, $attendance->application->status);
        $this->assertSame('09:00', $attendance->clock_in->format('H:i'));
        $this->assertSame('17:00', $attendance->clock_out->format('H:i'));
        // 9:00-17:00 休憩 60 分 時給 1,300 円 交通費 500 円 → 420 分 → 9,100 + 500 = 9,600 円
        $this->assertSame(420, $attendance->worked_minutes);
        $this->assertSame(9100, $attendance->wage_pay);
        $this->assertSame(9600, $attendance->total_pay);
    }

    public function test_同じ募集に同じワーカーは二重に応募できない_db制約(): void
    {
        $application = Application::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Application::factory()->create([
            'job_posting_id' => $application->job_posting_id,
            'worker_id' => $application->worker_id,
        ]);
    }

    public function test_勤怠は応募ごとに1件まで_db制約(): void
    {
        $attendance = Attendance::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Attendance::factory()->create(['application_id' => $attendance->application_id]);
    }

    public function test_募集に使われている拠点はdbでも削除できない(): void
    {
        $posting = JobPosting::factory()->create();

        $this->expectException(QueryException::class);

        // restrictOnDelete の確認。アプリでは LocationService が先に 422 で止める（担当 A）
        DB::table('locations')->where('id', $posting->location_id)->delete();
    }

    public function test_他社の募集と応募と勤怠とワーカーは見えない(): void
    {
        $otherCompany = Company::factory()->create();
        CurrentCompany::runAs($otherCompany->id, fn () => Attendance::factory()->create());
        Attendance::factory()->create();

        $this->assertSame(1, JobPosting::query()->count());
        $this->assertSame(1, Application::query()->count());
        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(1, Worker::query()->count());
    }

    public function test_sqliteは開始時に書き込みロックを取るimmediateで設定されている(): void
    {
        $config = config('database.connections.sqlite');

        $this->assertSame('IMMEDIATE', $config['transaction_mode']);
        $this->assertSame(5000, $config['busy_timeout']);
        $this->assertSame('wal', $config['journal_mode']);
    }

    public function test_最低賃金はconfigから読める(): void
    {
        $this->assertSame(1226, config('myteam.min_wage'));
    }

    public function test_immediateはphp84以上で2つ目のトランザクションの開始を待たせる(): void
    {
        // Laravel は PHP 8.4 以上のときだけ transaction_mode を使って BEGIN IMMEDIATE を発行する（SQLiteConnection）。
        // それ未満だと設定が黙って無視され、定員チェックの直列化（docs/01 の設計判断）が崩れるので前提として表明する
        $this->assertTrue(version_compare(PHP_VERSION, '8.4.0', '>='), 'PHP 8.4 以上が必要');

        // テストの DB は :memory:（接続ごとに別の DB）なので、一時ファイルに 2 本の接続を張って実物で確かめる
        $path = tempnam(sys_get_temp_dir(), 'immediate').'.sqlite';
        touch($path);
        foreach (['immediate_a', 'immediate_b'] as $name) {
            config(["database.connections.{$name}" => [
                ...config('database.connections.sqlite'), 'database' => $path, 'busy_timeout' => 0,
            ]]);
        }

        try {
            DB::connection('immediate_a')->beginTransaction();

            // 1 本目は何も書いていない。DEFERRED なら 2 本目も開始できるが、IMMEDIATE なら開始の時点でロックを取り合う
            try {
                DB::connection('immediate_b')->beginTransaction();
                $this->fail('2 本目のトランザクションが待たされずに開始できた（IMMEDIATE が効いていない）');
            } catch (PDOException $e) {
                // トランザクションの開始は PDO を直接呼ぶので、QueryException ではなく PDOException のまま上がる
                $this->assertStringContainsString('database is locked', $e->getMessage());
            }
        } finally {
            DB::connection('immediate_a')->rollBack();
            DB::purge('immediate_a');
            DB::purge('immediate_b');
            @unlink($path);
        }
    }

    public function test_他社の親を指す行は保存できない(): void
    {
        $other = Company::factory()->create();
        [$otherLocation, $otherWorker, $otherPosting, $otherApplication] = CurrentCompany::runAs($other->id, function () {
            $application = Application::factory()->confirmed()->create();

            return [Location::factory()->create(), Worker::factory()->create(), $application->jobPosting, $application];
        });
        $posting = JobPosting::factory()->create();
        $worker = Worker::factory()->create();

        $cases = [
            '募集の拠点が他社' => fn () => JobPosting::factory()->create(['location_id' => $otherLocation->id]),
            '応募の募集が他社' => fn () => Application::factory()->create(['job_posting_id' => $otherPosting->id, 'worker_id' => $worker->id]),
            '応募のワーカーが他社' => fn () => Application::factory()->create(['job_posting_id' => $posting->id, 'worker_id' => $otherWorker->id]),
            // ファクトリは応募を自社のスコープで読むので、ここでは列を全部渡してモデルから直接保存する
            '勤怠の応募が他社' => fn () => (new Attendance)->forceFill([
                'application_id' => $otherApplication->id, 'clock_in' => '2026-10-10 09:00:00', 'clock_out' => '2026-10-10 17:00:00',
                'break_minutes' => 60, 'worked_minutes' => 420, 'wage_pay' => 9100, 'total_pay' => 9600,
            ])->save(),
            '更新で拠点を他社に付け替え' => fn () => $posting->update(['location_id' => $otherLocation->id]),
        ];

        foreach ($cases as $label => $save) {
            try {
                $save();
                $this->fail("{$label}: LogicException が投げられていない");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNotSame($otherLocation->id, $posting->fresh()->location_id);
        $this->assertSame(1, JobPosting::query()->count());
    }

    public function test_勤務日はモデル経由でもy_m_dの文字列で保存され文字列比較が当たる(): void
    {
        JobPosting::factory()->create(['work_date' => '2026-10-10']);
        JobPosting::factory()->create(['work_date' => CarbonImmutable::parse('2026-10-31 15:00')]);
        JobPosting::factory()->create(['work_date' => '2026-11-01']);

        $this->assertEqualsCanonicalizing(
            ['2026-10-10', '2026-10-31', '2026-11-01'],
            DB::table('job_postings')->pluck('work_date')->all(),
        );
        $this->assertSame(1, JobPosting::query()->where('work_date', '2026-10-10')->count());
        // 月の範囲は半開区間（>= 月初 AND < 翌月初。docs/01 の業務ルールの決定）
        $this->assertSame(2, JobPosting::query()
            ->where('work_date', '>=', '2026-10-01')->where('work_date', '<', '2026-11-01')->count());
        $this->assertSame('2026-10-31', JobPosting::query()->where('work_date', '2026-10-31')->firstOrFail()->work_date->toDateString());
    }

    public function test_勤怠の金額の列はfillableではない(): void
    {
        foreach (['worked_minutes', 'wage_pay', 'total_pay'] as $column) {
            $this->assertFalse((new Attendance)->isFillable($column), $column);
        }
    }

    public function test_勤怠のファクトリに確定前の応募を渡すとlogic_exception(): void
    {
        $this->expectException(LogicException::class);

        Attendance::factory()->for(Application::factory())->create();
    }

    // ---- 以下 tester 追加（テスト補強） ----

    /**
     * 応募の 16 組を DB 越しに確かめる。許可される組だけ書き、それ以外は DomainException で保存されないこと
     *
     * @return iterable<string, array{ApplicationStatus, ApplicationStatus, bool}>
     */
    public static function applicationPairs(): iterable
    {
        $allowed = ['applied>confirmed', 'applied>rejected', 'confirmed>cancelled'];
        foreach (ApplicationStatus::cases() as $from) {
            foreach (ApplicationStatus::cases() as $to) {
                $key = "{$from->value}>{$to->value}";
                yield $key => [$from, $to, in_array($key, $allowed, true)];
            }
        }
    }

    #[DataProvider('applicationPairs')]
    public function test_応募のtransition_toは全16組で遷移表どおりに保存または拒否する(ApplicationStatus $from, ApplicationStatus $to, bool $allowed): void
    {
        $application = Application::factory()->create(['status' => $from]);
        // 差し戻し 3: 確定・キャンセルへの遷移は付随する列が必須になったので、契約どおりの列を渡す
        $attributes = match ($to) {
            ApplicationStatus::Confirmed => ['confirmed_at' => now(), 'confirmed_hourly_wage' => 1300, 'confirmed_transport_allowance' => 500],
            ApplicationStatus::Cancelled => ['cancel_reason' => CancelReason::Worker, 'cancelled_at' => now()],
            default => [],
        };

        try {
            $application->transitionTo($to, $attributes);
            $this->assertTrue($allowed, "{$from->value} → {$to->value} が通ってしまった");
            $this->assertSame($to, $application->fresh()->status);
        } catch (DomainException $e) {
            $this->assertFalse($allowed, "{$from->value} → {$to->value} が拒否された: {$e->getMessage()}");
            $this->assertSame("{$from->label()}の応募は{$to->label()}にできません。", $e->getMessage());
            $this->assertSame($from, $application->fresh()->status);
        }
    }

    /**
     * @return iterable<string, array{JobPostingStatus, JobPostingStatus, bool}>
     */
    public static function jobPostingPairs(): iterable
    {
        $allowed = ['open>closed', 'open>cancelled', 'closed>cancelled'];
        foreach (JobPostingStatus::cases() as $from) {
            foreach (JobPostingStatus::cases() as $to) {
                $key = "{$from->value}>{$to->value}";
                yield $key => [$from, $to, in_array($key, $allowed, true)];
            }
        }
    }

    #[DataProvider('jobPostingPairs')]
    public function test_募集のtransition_toは全9組で遷移表どおりに保存または拒否する(JobPostingStatus $from, JobPostingStatus $to, bool $allowed): void
    {
        $posting = JobPosting::factory()->create(['status' => $from]);

        try {
            $posting->transitionTo($to);
            $this->assertTrue($allowed, "{$from->value} → {$to->value} が通ってしまった");
            $this->assertSame($to, $posting->fresh()->status);
        } catch (DomainException $e) {
            $this->assertFalse($allowed, "{$from->value} → {$to->value} が拒否された: {$e->getMessage()}");
            $this->assertSame("{$from->label()}の募集は{$to->label()}にできません。", $e->getMessage());
            $this->assertSame($from, $posting->fresh()->status);
        }
    }

    public function test_遷移できないときは追加の列も保存しない(): void
    {
        $application = Application::factory()->rejected()->create();

        try {
            $application->transitionTo(ApplicationStatus::Confirmed, ['confirmed_hourly_wage' => 9999, 'confirmed_at' => now()]);
            $this->fail('DomainException が投げられていない');
        } catch (DomainException) {
            // 期待どおり
        }

        $fresh = $application->fresh();
        $this->assertNull($fresh->confirmed_hourly_wage);
        $this->assertNull($fresh->confirmed_at);
    }

    public function test_createとupdateとfillではstatusを変えられない(): void
    {
        $location = Location::factory()->create();
        $posting = JobPosting::create([
            'location_id' => $location->id, 'job_title' => '検品', 'work_date' => '2026-10-10',
            'start_time' => '09:00', 'end_time' => '17:00', 'capacity' => 2, 'hourly_wage' => 1300,
            'status' => 'cancelled',
        ]);
        $worker = Worker::factory()->create();
        $application = Application::create([
            'job_posting_id' => $posting->id, 'worker_id' => $worker->id, 'status' => 'confirmed',
        ]);

        $this->assertSame(JobPostingStatus::Open, $posting->fresh()->status);
        $this->assertSame(ApplicationStatus::Applied, $application->fresh()->status);

        $posting->update(['status' => 'cancelled', 'job_title' => '検品（変更）']);
        $application->update(['status' => 'confirmed']);
        $application->fill(['status' => 'rejected'])->save();

        $this->assertSame(JobPostingStatus::Open, $posting->fresh()->status);
        $this->assertSame('検品（変更）', $posting->fresh()->job_title);
        $this->assertSame(ApplicationStatus::Applied, $application->fresh()->status);
    }

    public function test_応募の確定時の値と理由区分は一括代入で変えられない(): void
    {
        $application = Application::factory()->confirmed()->create();

        $application->update([
            'confirmed_hourly_wage' => 9999,
            'confirmed_transport_allowance' => 9999,
            'confirmed_at' => null,
            'cancel_reason' => 'company',
            'cancelled_at' => now(),
        ]);

        $fresh = $application->fresh();
        $this->assertSame(1300, $fresh->confirmed_hourly_wage);
        $this->assertSame(500, $fresh->confirmed_transport_allowance);
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertNull($fresh->cancel_reason);
        $this->assertNull($fresh->cancelled_at);
    }

    public function test_拠点もcompany_idはfillableではない(): void
    {
        $this->assertFalse((new Location)->isFillable('company_id'));
    }

    public function test_作成時に他社のcompany_idを渡しても自社で作られる(): void
    {
        $other = Company::factory()->create();
        $location = Location::factory()->create();

        $worker = Worker::create(['name' => '佐藤', 'email' => 'sato@example.com', 'company_id' => $other->id]);
        $posting = JobPosting::create([
            'location_id' => $location->id, 'job_title' => '梱包', 'work_date' => '2026-10-10',
            'start_time' => '09:00', 'end_time' => '17:00', 'capacity' => 1, 'hourly_wage' => 1300,
            'company_id' => $other->id,
        ]);
        $application = Application::create(['job_posting_id' => $posting->id, 'worker_id' => $worker->id, 'company_id' => $other->id]);

        foreach ([$worker, $posting, $application] as $model) {
            $this->assertSame($this->company->id, $model->fresh()->company_id, $model::class);
        }
    }

    public function test_4モデルとも他社のidはfindで見つからずcompany_idの付け替えはlogic_exception(): void
    {
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Attendance::factory()->create());
        $theirApplication = CurrentCompany::runAs($other->id, fn () => Application::query()->findOrFail($theirs->application_id));

        $ids = [
            Worker::class => $theirApplication->worker_id,
            JobPosting::class => $theirApplication->job_posting_id,
            Application::class => $theirApplication->id,
            Attendance::class => $theirs->id,
        ];
        foreach ($ids as $class => $id) {
            $this->assertNull($class::query()->find($id), "{$class} の他社の行が見えている");
        }

        $mine = Attendance::factory()->create();
        $models = [$mine, $mine->application, $mine->application->jobPosting, $mine->application->worker];
        foreach ($models as $model) {
            try {
                $model->forceFill(['company_id' => $other->id])->save();
                $this->fail($model::class.' の company_id を付け替えられてしまった');
            } catch (LogicException) {
                $this->assertSame($this->company->id, $model->fresh()->company_id, $model::class);
            }
        }
    }

    public function test_応募のファクトリは募集とワーカーを同じ企業に作る(): void
    {
        $other = Company::factory()->create();

        $mine = Application::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Application::factory()->create()->load('jobPosting.location', 'worker'));

        $this->assertSame($this->company->id, $mine->jobPosting->company_id);
        $this->assertSame($this->company->id, $mine->jobPosting->location->company_id);
        $this->assertSame($this->company->id, $mine->worker->company_id);
        $this->assertSame($other->id, $theirs->company_id);
        $this->assertSame($other->id, $theirs->jobPosting->company_id);
        $this->assertSame($other->id, $theirs->jobPosting->location->company_id);
        $this->assertSame($other->id, $theirs->worker->company_id);
    }

    public function test_応募中と却下のファクトリは確定時の値と理由区分を持たない(): void
    {
        foreach ([Application::factory()->create(), Application::factory()->rejected()->create()] as $application) {
            $this->assertNull($application->confirmed_at);
            $this->assertNull($application->confirmed_hourly_wage);
            $this->assertNull($application->confirmed_transport_allowance);
            $this->assertNull($application->cancel_reason);
            $this->assertNull($application->cancelled_at);
        }
        $this->assertSame(ApplicationStatus::Rejected, Application::factory()->rejected()->create()->status);
    }

    public function test_確定のファクトリは既定の募集なら時給1300円と交通費500円をコピーする(): void
    {
        $application = Application::factory()->confirmed()->create();

        $this->assertSame($application->jobPosting->hourly_wage, $application->confirmed_hourly_wage);
        $this->assertSame(1300, $application->confirmed_hourly_wage);
        $this->assertSame(500, $application->confirmed_transport_allowance);
    }

    public function test_キャンセルのファクトリは既定でワーカー都合で募集の時給をコピーする(): void
    {
        $posting = JobPosting::factory()->create(['hourly_wage' => 1450, 'transport_allowance' => 0]);

        $application = Application::factory()->for($posting)->cancelled()->create();

        $this->assertSame(CancelReason::Worker, $application->cancel_reason);
        $this->assertSame(1450, $application->confirmed_hourly_wage);
        $this->assertSame(0, $application->confirmed_transport_allowance);
        $this->assertTrue($application->confirmed_at->lessThan($application->cancelled_at));
    }

    public function test_募集のファクトリの状態と勤務日と無効ワーカーのファクトリ(): void
    {
        $this->assertSame(JobPostingStatus::Open, JobPosting::factory()->create()->status);
        $this->assertSame(JobPostingStatus::Closed, JobPosting::factory()->closed()->create()->status);
        $this->assertSame(JobPostingStatus::Cancelled, JobPosting::factory()->cancelled()->create()->status);
        $this->assertSame('2026-09-30', JobPosting::factory()->onDate('2026-09-30')->create()->work_date->toDateString());
        $this->assertGreaterThanOrEqual(config('myteam.min_wage'), JobPosting::factory()->create()->hourly_wage);

        $inactive = Worker::factory()->inactive()->create();
        $this->assertFalse($inactive->fresh()->is_active);
        $this->assertNotEmpty($inactive->email);
    }

    public function test_勤怠のファクトリは確定時の時給で計算し募集を後から変えても影響しない(): void
    {
        $application = Application::factory()->confirmed()->create();
        $application->jobPosting->update(['hourly_wage' => 2000, 'transport_allowance' => 0]);

        $attendance = Attendance::factory()->for($application)->create();

        // 420 分 × 1,300 円 = 9,100 円 + 確定時の交通費 500 円
        $this->assertSame(9100, $attendance->wage_pay);
        $this->assertSame(9600, $attendance->total_pay);
    }

    public function test_勤怠のファクトリは深夜跨ぎの募集なら退勤を翌日にする(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-31')->create(['start_time' => '22:00', 'end_time' => '05:00']);
        $application = Application::factory()->for($posting)->confirmed()->create();

        $attendance = Attendance::factory()->for($application)->create();

        $this->assertSame('2026-10-31 22:00', $attendance->clock_in->format('Y-m-d H:i'));
        $this->assertSame('2026-11-01 05:00', $attendance->clock_out->format('Y-m-d H:i'));
        // 7 時間 − 休憩 60 分 = 360 分 → 360 × 1,300 / 60 = 7,800 円
        $this->assertSame(360, $attendance->worked_minutes);
        $this->assertSame(7800, $attendance->wage_pay);
        $this->assertSame(8300, $attendance->total_pay);
    }

    public function test_勤怠のファクトリは円未満を切り上げる(): void
    {
        $posting = JobPosting::factory()->create(['hourly_wage' => 1001, 'start_time' => '09:00', 'end_time' => '09:01']);
        $application = Application::factory()->for($posting)->confirmed()->create();

        $attendance = Attendance::factory()->for($application)->create(['break_minutes' => 0]);

        // 1 分 × 1,001 円 / 60 = 16.68… → 17 円
        $this->assertSame(1, $attendance->worked_minutes);
        $this->assertSame(17, $attendance->wage_pay);
    }
}
