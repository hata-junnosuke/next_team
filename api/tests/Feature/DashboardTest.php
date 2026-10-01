<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * F-19 ダッシュボード（docs/01 6.8）。
 * 今日以降の募集中・締切の募集（勤務日の昇順、最大 20 件）、充足率（整数の％、四捨五入）、応募中の件数、生成時刻。
 * 「今日」は app.timezone（Asia/Tokyo）。時刻は travelTo で 2026-10-01 10:00 に固定する。
 */
class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
    }

    private function getDashboard(): TestResponse
    {
        return $this->actingAsAdmin()->getJson('/api/dashboard');
    }

    /**
     * 確定 $confirmed 件の募集を作る
     */
    private function postingWithConfirmed(int $capacity, int $confirmed, string $date = '2026-10-05'): JobPosting
    {
        $posting = JobPosting::factory()->onDate($date)->create(['capacity' => $capacity]);
        Application::factory()->count($confirmed)->for($posting)->confirmed()->create();

        return $posting;
    }

    public function test_今日以降の募集と充足率と未処理応募数が返る(): void
    {
        $tokyo = Location::factory()->create(['name' => '東京倉庫']);
        $later = JobPosting::factory()->onDate('2026-10-02')->closed()->for($tokyo)->create([
            'job_title' => '検品', 'start_time' => '13:00', 'end_time' => '18:00', 'capacity' => 1,
        ]);
        $today = JobPosting::factory()->onDate('2026-10-01')->for($tokyo)->create([
            'job_title' => 'ピッキング', 'start_time' => '09:00', 'end_time' => '17:00', 'capacity' => 3,
        ]);
        Application::factory()->count(2)->for($today)->confirmed()->create();
        Application::factory()->count(3)->for($today)->create();
        Application::factory()->for($today)->rejected()->create();
        Application::factory()->for($today)->cancelled()->create();
        Application::factory()->for($later)->create();

        $this->getDashboard()
            ->assertOk()
            ->assertExactJson(['data' => [
                'upcoming_postings' => [
                    [
                        'id' => $today->id, 'work_date' => '2026-10-01', 'start_time' => '09:00', 'end_time' => '17:00',
                        'job_title' => 'ピッキング', 'location_name' => '東京倉庫',
                        'capacity' => 3, 'confirmed_count' => 2, 'fill_rate' => 67,
                    ],
                    [
                        'id' => $later->id, 'work_date' => '2026-10-02', 'start_time' => '13:00', 'end_time' => '18:00',
                        'job_title' => '検品', 'location_name' => '東京倉庫',
                        'capacity' => 1, 'confirmed_count' => 0, 'fill_rate' => 0,
                    ],
                ],
                // 応募中（applied）だけを数える。確定・却下・キャンセルは含めない
                'pending_applications_count' => 4,
                'generated_at' => '2026-10-01T10:00:00+09:00',
            ]]);
    }

    public function test_中止した募集と過去の募集は出ない(): void
    {
        $open = JobPosting::factory()->onDate('2026-10-03')->create();
        JobPosting::factory()->onDate('2026-10-03')->cancelled()->create();
        JobPosting::factory()->onDate('2026-09-30')->create();
        JobPosting::factory()->onDate('2026-09-30')->closed()->create();

        $this->getDashboard()
            ->assertOk()
            ->assertJsonCount(1, 'data.upcoming_postings')
            ->assertJsonPath('data.upcoming_postings.0.id', $open->id);
    }

    public function test_fill_rateは四捨五入の整数(): void
    {
        $this->postingWithConfirmed(capacity: 8, confirmed: 1, date: '2026-10-02');  // 12.5 → 13
        $this->postingWithConfirmed(capacity: 3, confirmed: 1, date: '2026-10-03');  // 33.3 → 33
        $this->postingWithConfirmed(capacity: 3, confirmed: 2, date: '2026-10-04');  // 66.7 → 67
        $this->postingWithConfirmed(capacity: 2, confirmed: 2, date: '2026-10-05');  // 100

        $rates = collect($this->getDashboard()->assertOk()->json('data.upcoming_postings'))->pluck('fill_rate')->all();

        $this->assertSame([13, 33, 67, 100], $rates);
    }

    public function test_満員でなければfill_rateは100にならない(): void
    {
        $this->postingWithConfirmed(capacity: 200, confirmed: 199, date: '2026-10-02');  // 99.5 → 四捨五入なら 100 だが上限 99
        $this->postingWithConfirmed(capacity: 200, confirmed: 200, date: '2026-10-03');  // 満員なら 100

        $rates = collect($this->getDashboard()->assertOk()->json('data.upcoming_postings'))->pluck('fill_rate')->all();

        $this->assertSame([99, 100], $rates);
    }

    public function test_勤務日の昇順で最大20件(): void
    {
        foreach (range(21, 1) as $day) {
            JobPosting::factory()->onDate(sprintf('2026-10-%02d', $day))->create();
        }

        $dates = collect($this->getDashboard()->assertOk()->json('data.upcoming_postings'))->pluck('work_date')->all();

        $this->assertCount(20, $dates);
        $this->assertSame('2026-10-01', $dates[0]);
        $this->assertSame('2026-10-20', $dates[19]);
    }

    public function test_他社の募集と応募は出ない(): void
    {
        $ours = JobPosting::factory()->onDate('2026-10-05')->create();
        Application::factory()->for($ours)->create();
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, function () {
            $theirs = JobPosting::factory()->onDate('2026-10-02')->create();
            Application::factory()->count(2)->for($theirs)->create();
        });

        // 本番のリクエストは CurrentCompany が空の状態で始まる。setUp で入れた値に助けられないよう消してから叩く
        Context::forget('company_id');

        $this->getDashboard()
            ->assertOk()
            ->assertJsonCount(1, 'data.upcoming_postings')
            ->assertJsonPath('data.upcoming_postings.0.id', $ours->id)
            ->assertJsonPath('data.pending_applications_count', 1);
    }

    public function test_募集の件数が増えてもクエリ数は増えない(): void
    {
        $this->postingWithConfirmed(capacity: 3, confirmed: 1);
        $this->getDashboard()->assertOk();

        DB::enableQueryLog();
        $this->getDashboard()->assertOk();
        $one = count(DB::getQueryLog());

        foreach (range(2, 6) as $day) {
            $this->postingWithConfirmed(capacity: 3, confirmed: 1, date: sprintf('2026-10-%02d', $day));
        }
        DB::flushQueryLog();
        $this->getDashboard()->assertOk()->assertJsonCount(6, 'data.upcoming_postings');

        $this->assertSame($one, count(DB::getQueryLog()));
    }

    // ---- ここから tester が補強（P2-06） ----

    public function test_データがなければ空の一覧と0件(): void
    {
        $this->getDashboard()
            ->assertOk()
            ->assertExactJson(['data' => [
                'upcoming_postings' => [],
                'pending_applications_count' => 0,
                'generated_at' => '2026-10-01T10:00:00+09:00',
            ]]);
    }

    public function test_日付が変わった直後は今日の募集が出て昨日の深夜の募集は出ない(): void
    {
        // 2026-10-01 00:00 JST は UTC ではまだ 9/30。「今日」は app.timezone で決まること
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(0, 0));
        $today = JobPosting::factory()->onDate('2026-10-01')->create(['start_time' => '00:00', 'end_time' => '08:00']);
        JobPosting::factory()->onDate('2026-09-30')->create(['start_time' => '22:00', 'end_time' => '06:00']);

        $this->getDashboard()
            ->assertOk()
            ->assertJsonCount(1, 'data.upcoming_postings')
            ->assertJsonPath('data.upcoming_postings.0.id', $today->id);
    }

    public function test_今日の終わりでも今日の募集は出る(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(23, 59, 59));
        $today = JobPosting::factory()->onDate('2026-10-01')->create(['start_time' => '09:00', 'end_time' => '17:00']);

        $this->getDashboard()
            ->assertOk()
            ->assertJsonCount(1, 'data.upcoming_postings')
            ->assertJsonPath('data.upcoming_postings.0.id', $today->id);
    }

    public function test_締切の募集は確定数と充足率つきで出る(): void
    {
        $closed = JobPosting::factory()->onDate('2026-10-03')->closed()->create(['capacity' => 2]);
        Application::factory()->count(2)->for($closed)->confirmed()->create();

        $this->getDashboard()
            ->assertOk()
            ->assertJsonCount(1, 'data.upcoming_postings')
            ->assertJsonPath('data.upcoming_postings.0.id', $closed->id)
            ->assertJsonPath('data.upcoming_postings.0.confirmed_count', 2)
            ->assertJsonPath('data.upcoming_postings.0.fill_rate', 100);
    }

    public function test_同じ勤務日は開始時刻の順(): void
    {
        $afternoon = JobPosting::factory()->onDate('2026-10-02')->create(['start_time' => '13:00', 'end_time' => '18:00']);
        $morning = JobPosting::factory()->onDate('2026-10-02')->create(['start_time' => '09:00', 'end_time' => '12:00']);

        $ids = collect($this->getDashboard()->assertOk()->json('data.upcoming_postings'))->pluck('id')->all();

        $this->assertSame([$morning->id, $afternoon->id], $ids);
    }

    public function test_過去と中止の募集は20件の枠を使わない(): void
    {
        foreach (range(1, 5) as $day) {
            JobPosting::factory()->onDate(sprintf('2026-09-%02d', $day))->create();
            JobPosting::factory()->onDate(sprintf('2026-10-%02d', $day))->cancelled()->create();
        }
        foreach (range(1, 20) as $day) {
            JobPosting::factory()->onDate(sprintf('2026-10-%02d', $day))->create();
        }

        $dates = collect($this->getDashboard()->assertOk()->json('data.upcoming_postings'))->pluck('work_date')->all();

        $this->assertCount(20, $dates);
        $this->assertSame('2026-10-01', $dates[0]);
        $this->assertSame('2026-10-20', $dates[19]);
    }

    public function test_fill_rateの境界(): void
    {
        $this->postingWithConfirmed(capacity: 3, confirmed: 0, date: '2026-10-02');    // 0
        $this->postingWithConfirmed(capacity: 200, confirmed: 1, date: '2026-10-03');  // 0.5 → 1（.5 は切り上げ）
        $this->postingWithConfirmed(capacity: 201, confirmed: 1, date: '2026-10-04');  // 0.497 → 0
        $this->postingWithConfirmed(capacity: 8, confirmed: 7, date: '2026-10-05');    // 87.5 → 88
        $this->postingWithConfirmed(capacity: 1, confirmed: 1, date: '2026-10-06');    // 100

        $rates = collect($this->getDashboard()->assertOk()->json('data.upcoming_postings'))->pluck('fill_rate')->all();

        $this->assertSame([0, 1, 0, 88, 100], $rates);
    }

    public function test_確定数は確定だけを数えキャンセルと却下と応募中は含めない(): void
    {
        $posting = $this->postingWithConfirmed(capacity: 4, confirmed: 1, date: '2026-10-02');
        Application::factory()->for($posting)->create();
        Application::factory()->for($posting)->rejected()->create();
        Application::factory()->for($posting)->cancelled()->create();

        $this->getDashboard()
            ->assertOk()
            ->assertJsonPath('data.upcoming_postings.0.confirmed_count', 1)
            ->assertJsonPath('data.upcoming_postings.0.fill_rate', 25);
    }

    public function test_応募中の件数は締切や20件の外の募集の応募も数える(): void
    {
        // 締切後も応募中の応募は承認できる（docs/01 4.6）ので未処理として数える
        $closed = JobPosting::factory()->onDate('2026-10-02')->closed()->create();
        Application::factory()->for($closed)->create();
        foreach (range(1, 21) as $day) {
            JobPosting::factory()->onDate(sprintf('2026-10-%02d', $day))->create();
        }
        // 21 件目より後（一覧に出ない）の募集の応募
        $outside = JobPosting::factory()->onDate('2026-10-25')->create();
        Application::factory()->count(2)->for($outside)->create();

        $this->getDashboard()
            ->assertOk()
            ->assertJsonCount(20, 'data.upcoming_postings')
            ->assertJsonPath('data.pending_applications_count', 3);
    }

    public function test_他社の行に書き換えた応募は確定数にも応募中の件数にも入らない(): void
    {
        $other = Company::factory()->create();
        $posting = $this->postingWithConfirmed(capacity: 4, confirmed: 1, date: '2026-10-02');
        $foreignConfirmed = Application::factory()->for($posting)->confirmed()->create();
        $foreignApplied = Application::factory()->for($posting)->create();
        Application::factory()->for($posting)->create();
        DB::table('applications')->whereIn('id', [$foreignConfirmed->id, $foreignApplied->id])->update(['company_id' => $other->id]);

        Context::forget('company_id');

        $this->getDashboard()
            ->assertOk()
            ->assertJsonPath('data.upcoming_postings.0.confirmed_count', 1)
            ->assertJsonPath('data.pending_applications_count', 1);
    }

    public function test_応答の形がdocs01の6_8どおり(): void
    {
        $this->postingWithConfirmed(capacity: 3, confirmed: 1);

        $this->getDashboard()
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'upcoming_postings' => [['id', 'work_date', 'start_time', 'end_time', 'job_title', 'location_name', 'capacity', 'confirmed_count', 'fill_rate']],
                'pending_applications_count',
                'generated_at',
            ]])
            ->assertJsonPath('data.upcoming_postings.0.start_time', '09:00')
            ->assertJsonPath('data.upcoming_postings.0.end_time', '17:00');
    }
}
