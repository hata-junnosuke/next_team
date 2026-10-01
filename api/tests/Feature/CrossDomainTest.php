<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Worker;
use Tests\TestCase;

/**
 * 担当 A（拠点・ワーカー）/ B（募集・応募）/ C（勤怠・集計・ダッシュボード）の境界をまたぐ統合テスト（P2-07）。
 * 各担当のテストはファクトリで状態を作って自分の機能を確かめている。ここでは状態の変化をすべて API で起こし、
 * 別の担当の機能（拠点の削除・応募できるワーカー・月次集計・CSV・ダッシュボード）にどう見えるかを確かめる。
 * 時刻は 2026-09-01 10:00 に固定し、勤務日 2026-09-20 をダッシュボードの「今日以降」に入れる。
 */
class CrossDomainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 9, 1)->setTime(10, 0));
        $this->actingAsAdmin();
    }

    /**
     * API で募集を作る（既定は 9:00-17:00、定員 3、時給 1,300 円、交通費 500 円）
     *
     * @param  array<string, mixed>  $override
     */
    private function createPosting(Location $location, array $override = []): int
    {
        return $this->postJson('/api/job-postings', $override + [
            'location_id' => $location->id,
            'job_title' => 'ピッキング',
            'work_date' => '2026-09-20',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 3,
            'hourly_wage' => 1300,
            'transport_allowance' => 500,
        ])->assertCreated()->json('data.id');
    }

    private function apply(int $postingId, Worker $worker): int
    {
        return $this->postJson("/api/job-postings/{$postingId}/applications", ['worker_id' => $worker->id])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * API で応募 → 承認 → 勤怠（9:00-17:00 休憩 60 分）まで進め、応募 ID を返す
     */
    private function confirmedShift(int $postingId, Worker $worker): int
    {
        $applicationId = $this->apply($postingId, $worker);
        $this->postJson("/api/applications/{$applicationId}/confirm")->assertOk();
        $this->putJson("/api/applications/{$applicationId}/attendance", [
            'clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60,
        ])->assertOk();

        return $applicationId;
    }

    /**
     * @return list<string> CSV のデータ行（BOM とヘッダを除く）
     */
    private function csvDataLines(string $month): array
    {
        $body = $this->get("/api/reports/monthly/csv?month={$month}")->assertOk()->streamedContent();
        $lines = preg_split('/\r\n|\n/', rtrim(substr($body, 3), "\r\n"));

        return array_slice($lines, 1);
    }

    public function test_api_で募集を中止すると拠点は削除できないままで_deletable_もfalse(): void
    {
        $location = Location::factory()->create();
        $postingId = $this->createPosting($location);
        $this->postJson("/api/job-postings/{$postingId}/cancel")->assertOk();

        $this->getJson("/api/locations/{$location->id}")
            ->assertOk()
            ->assertJsonPath('data.job_postings_count', 1)
            ->assertJsonPath('data.deletable', false);
        $this->deleteJson("/api/locations/{$location->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', '募集に使われている拠点は削除できません。');
        $this->assertDatabaseHas('locations', ['id' => $location->id]);
    }

    public function test_勤怠の後に_api_で応募をキャンセルすると月次集計と_csv_から外れる(): void
    {
        $location = Location::factory()->create();
        $postingId = $this->createPosting($location);
        $keep = Worker::factory()->create(['name' => '残る人']);
        $leave = Worker::factory()->create(['name' => '外れる人']);
        $this->confirmedShift($postingId, $keep);
        $leaveApplicationId = $this->confirmedShift($postingId, $leave);

        $this->getJson('/api/reports/monthly?month=2026-09')->assertJsonPath('data.total.shifts', 2);

        $this->postJson("/api/applications/{$leaveApplicationId}/cancel", ['reason' => 'worker'])->assertOk();

        $this->getJson('/api/reports/monthly?month=2026-09')
            ->assertOk()
            ->assertJsonPath('data.by_worker', [
                ['worker_id' => $keep->id, 'name' => '残る人', 'shifts' => 1, 'worked_minutes' => 420, 'total_pay' => 9600],
            ])
            ->assertJsonPath('data.total', ['shifts' => 1, 'worked_minutes' => 420, 'total_pay' => 9600]);
        $lines = $this->csvDataLines('2026-09');
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('残る人', $lines[0]);
        // 勤怠の行自体は消えない（集計の対象から外れるだけ。docs/01 6.6）
        $this->assertDatabaseHas('attendances', ['application_id' => $leaveApplicationId]);
    }

    public function test_勤怠入力後に_api_で募集を中止すると月次集計とダッシュボードから外れ未処理の応募も減る(): void
    {
        $location = Location::factory()->create();
        $postingId = $this->createPosting($location);
        $confirmedId = $this->confirmedShift($postingId, Worker::factory()->create());
        $appliedId = $this->apply($postingId, Worker::factory()->create());

        $this->getJson('/api/dashboard')
            ->assertJsonCount(1, 'data.upcoming_postings')
            ->assertJsonPath('data.pending_applications_count', 1);

        $this->postJson("/api/job-postings/{$postingId}/cancel")->assertOk();

        $applications = collect($this->getJson("/api/job-postings/{$postingId}/applications")->json('data'));
        $this->assertSame(['value' => 'cancelled', 'label' => 'キャンセル'], $applications->firstWhere('id', $confirmedId)['status']);
        $this->assertSame(['value' => 'company', 'label' => '企業都合'], $applications->firstWhere('id', $confirmedId)['cancel_reason']);
        $this->assertSame('rejected', $applications->firstWhere('id', $appliedId)['status']['value']);

        $this->getJson('/api/reports/monthly?month=2026-09')
            ->assertOk()
            ->assertJsonPath('data.by_worker', [])
            ->assertJsonPath('data.total', ['shifts' => 0, 'worked_minutes' => 0, 'total_pay' => 0]);
        $this->assertSame([], $this->csvDataLines('2026-09'));
        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.upcoming_postings', [])
            ->assertJsonPath('data.pending_applications_count', 0);
    }

    public function test_api_で無効にしたワーカーは応募できるワーカーから消えるが既存の確定と勤怠は月次集計に残る(): void
    {
        $location = Location::factory()->create();
        $first = $this->createPosting($location);
        $second = $this->createPosting($location, ['work_date' => '2026-09-21']);
        $worker = Worker::factory()->create(['name' => '途中で無効']);
        $applicationId = $this->apply($first, $worker);
        $this->postJson("/api/applications/{$applicationId}/confirm")->assertOk();

        $this->patchJson("/api/workers/{$worker->id}/active", ['is_active' => false])->assertOk();

        // 新しい募集の選択肢には出ず、応募もできない
        $this->getJson("/api/job-postings/{$second}/applicable-workers")
            ->assertOk()
            ->assertExactJson(['data' => []]);
        $this->postJson("/api/job-postings/{$second}/applications", ['worker_id' => $worker->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.worker_id.0', '無効のワーカーは応募できません。');

        // 無効にする前の確定には勤怠を入力でき、集計にも残る（docs/01 4.6「既存の確定・応募は影響を受けない」）
        $this->putJson("/api/applications/{$applicationId}/attendance", [
            'clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60,
        ])->assertOk()->assertJsonPath('data.total_pay', 9600);
        $this->getJson('/api/reports/monthly?month=2026-09')
            ->assertOk()
            ->assertJsonPath('data.by_worker', [
                ['worker_id' => $worker->id, 'name' => '途中で無効', 'shifts' => 1, 'worked_minutes' => 420, 'total_pay' => 9600],
            ]);
        $this->getJson("/api/job-postings/{$first}")->assertJsonPath('data.confirmed_count', 1);
    }

    public function test_ダッシュボードの確定数と充足率と未処理の応募数は承認却下キャンセルの_api_で変わる(): void
    {
        $location = Location::factory()->create();
        $postingId = $this->createPosting($location);
        [$a, $b, $c] = array_map(
            fn (Worker $w) => $this->apply($postingId, $w),
            Worker::factory()->count(3)->create()->all(),
        );

        $this->getJson('/api/dashboard')
            ->assertJsonPath('data.upcoming_postings.0.confirmed_count', 0)
            ->assertJsonPath('data.upcoming_postings.0.fill_rate', 0)
            ->assertJsonPath('data.pending_applications_count', 3);

        $this->postJson("/api/applications/{$a}/confirm")->assertOk();
        $this->postJson("/api/applications/{$b}/confirm")->assertOk();
        $this->postJson("/api/applications/{$c}/reject")->assertOk();

        $this->getJson('/api/dashboard')
            ->assertJsonPath('data.upcoming_postings.0.confirmed_count', 2)
            ->assertJsonPath('data.upcoming_postings.0.fill_rate', 67)
            ->assertJsonPath('data.pending_applications_count', 0);

        $this->postJson("/api/applications/{$a}/cancel", ['reason' => 'company'])->assertOk();

        $this->getJson('/api/dashboard')
            ->assertJsonPath('data.upcoming_postings.0.confirmed_count', 1)
            ->assertJsonPath('data.upcoming_postings.0.fill_rate', 33);
        $this->getJson("/api/job-postings/{$postingId}")
            ->assertJsonPath('data.applications_count', 3)
            ->assertJsonPath('data.confirmed_count', 1);
    }

    public function test_api_で締め切った募集はダッシュボードに残り締切後の承認と勤怠も集計に入る(): void
    {
        $location = Location::factory()->create();
        $postingId = $this->createPosting($location);
        $worker = Worker::factory()->create();
        $applicationId = $this->apply($postingId, $worker);

        $this->postJson("/api/job-postings/{$postingId}/close")->assertOk();
        // 締切後は新規応募できない
        $this->postJson("/api/job-postings/{$postingId}/applications", ['worker_id' => Worker::factory()->create()->id])
            ->assertUnprocessable();

        $this->postJson("/api/applications/{$applicationId}/confirm")->assertOk();
        $this->putJson("/api/applications/{$applicationId}/attendance", [
            'clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60,
        ])->assertOk();

        $this->getJson('/api/dashboard')
            ->assertJsonPath('data.upcoming_postings.0.id', $postingId)
            ->assertJsonPath('data.upcoming_postings.0.confirmed_count', 1);
        $this->getJson('/api/reports/monthly?month=2026-09')
            ->assertJsonPath('data.total', ['shifts' => 1, 'worked_minutes' => 420, 'total_pay' => 9600]);
    }

    public function test_確定後に_api_で募集の時給を変えても勤怠と月次集計は確定時の時給で後の確定は新しい時給(): void
    {
        $location = Location::factory()->create();
        $postingId = $this->createPosting($location);
        $before = Worker::factory()->create(['name' => '変更前']);
        $after = Worker::factory()->create(['name' => '変更後']);
        $beforeApplicationId = $this->apply($postingId, $before);
        $this->postJson("/api/applications/{$beforeApplicationId}/confirm")->assertOk();

        $this->putJson("/api/job-postings/{$postingId}", [
            'location_id' => $location->id,
            'job_title' => 'ピッキング',
            'work_date' => '2026-09-20',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 3,
            'hourly_wage' => 1500,
            'transport_allowance' => 800,
        ])->assertOk();
        $this->putJson("/api/applications/{$beforeApplicationId}/attendance", [
            'clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60,
        ])->assertOk();
        $this->confirmedShift($postingId, $after);

        // 変更前: 420 分 × 1,300 円 = 9,100 + 500 = 9,600 円 / 変更後: 420 分 × 1,500 円 = 10,500 + 800 = 11,300 円
        $byWorker = collect($this->getJson('/api/reports/monthly?month=2026-09')->assertOk()->json('data.by_worker'))
            ->pluck('total_pay', 'name')
            ->all();
        // キーの順は問わない（assertEquals は連想配列の順を見ない）
        $this->assertEquals(['変更前' => 9600, '変更後' => 11300], $byWorker);
        $this->getJson('/api/reports/monthly?month=2026-09')->assertJsonPath('data.total.total_pay', 20900);
    }

    public function test_api_で複製した募集は応募が空で元の応募者も応募できるワーカーに出る(): void
    {
        $location = Location::factory()->create();
        $postingId = $this->createPosting($location);
        $worker = Worker::factory()->create(['name' => '元の応募者']);
        $applicationId = $this->apply($postingId, $worker);
        $this->postJson("/api/applications/{$applicationId}/confirm")->assertOk();

        $copyId = $this->postJson("/api/job-postings/{$postingId}/duplicate", ['work_date' => '2026-09-27'])
            ->assertCreated()
            ->assertJsonPath('data.applications_count', 0)
            ->assertJsonPath('data.confirmed_count', 0)
            ->json('data.id');

        $this->getJson("/api/job-postings/{$copyId}/applications")->assertOk()->assertExactJson(['data' => []]);
        $this->getJson("/api/job-postings/{$copyId}/applicable-workers")
            ->assertOk()
            ->assertExactJson(['data' => [['id' => $worker->id, 'name' => '元の応募者']]]);
        $this->getJson("/api/locations/{$location->id}")->assertJsonPath('data.job_postings_count', 2);
        $this->getJson('/api/dashboard')
            ->assertJsonCount(2, 'data.upcoming_postings')
            ->assertJsonPath('data.upcoming_postings.1.id', $copyId)
            ->assertJsonPath('data.upcoming_postings.1.confirmed_count', 0);
    }
}
