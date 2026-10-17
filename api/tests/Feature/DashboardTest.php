<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\User;
use App\Models\Worker;
use App\Services\DashboardService;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * F-19 ダッシュボード（docs/01 のダッシュボードの API）。
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

        // キャッシュに助けられないよう、測る前に毎回消す（2 回目はキャッシュから返るため）
        Cache::flush();
        DB::enableQueryLog();
        $this->getDashboard()->assertOk();
        $one = count(DB::getQueryLog());

        foreach (range(2, 6) as $day) {
            $this->postingWithConfirmed(capacity: 3, confirmed: 1, date: sprintf('2026-10-%02d', $day));
        }
        Cache::flush();
        DB::flushQueryLog();
        $this->getDashboard()->assertOk()->assertJsonCount(6, 'data.upcoming_postings');

        $this->assertSame($one, count(DB::getQueryLog()));
    }

    // ---- ここから tester が補強 ----

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
        // 締切後も応募中の応募は承認できる（docs/01 の業務ルールの決定）ので未処理として数える
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

    // ---- キャッシュ（docs/01 の非同期の契約） ----

    /**
     * ダッシュボードを集計するクエリ（募集・応募のテーブルを読むもの）だけを数える。認証やセッションのクエリは数えない
     */
    private function dashboardQueryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        return $queries->filter(fn (string $sql) => str_contains($sql, 'job_postings') || str_contains($sql, 'applications'))->count();
    }

    private function generatedAt(): string
    {
        return $this->getDashboard()->assertOk()->json('data.generated_at');
    }

    public function test_2回目の取得はキャッシュから返りgenerated_atが同じ(): void
    {
        $this->postingWithConfirmed(capacity: 3, confirmed: 1);
        $first = $this->getDashboard()->assertOk()->json('data');

        $this->travel(3)->minutes();
        $queries = $this->dashboardQueryCount(function () use ($first) {
            $this->getDashboard()->assertOk()->assertExactJson(['data' => $first]);
        });

        $this->assertSame(0, $queries);
        // generated_at はキャッシュした時刻（2 回目の時刻 10:03 ではない）
        $this->assertSame('2026-10-01T10:00:00+09:00', $first['generated_at']);
    }

    public function test_キャッシュのキーはdashboard_企業_i_dで値が入る(): void
    {
        $this->getDashboard()->assertOk();

        $this->assertTrue(Cache::has('dashboard:'.$this->company->id));
    }

    public function test_承認するとキャッシュが破棄され充足率が更新される(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-05')->create(['capacity' => 2]);
        $application = Application::factory()->for($posting)->create();
        $this->getDashboard()
            ->assertJsonPath('data.upcoming_postings.0.fill_rate', 0)
            ->assertJsonPath('data.pending_applications_count', 1);

        $this->travel(1)->minutes();
        $this->actingAsAdmin()->postJson("/api/applications/{$application->id}/confirm")->assertOk();

        $this->getDashboard()
            ->assertJsonPath('data.upcoming_postings.0.confirmed_count', 1)
            ->assertJsonPath('data.upcoming_postings.0.fill_rate', 50)
            ->assertJsonPath('data.pending_applications_count', 0)
            ->assertJsonPath('data.generated_at', '2026-10-01T10:01:00+09:00');
    }

    public function test_応募を追加するとキャッシュが破棄され未処理の件数が増える(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-05')->create();
        $this->getDashboard()->assertJsonPath('data.pending_applications_count', 0);

        $worker = Worker::factory()->create();
        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => $worker->id])->assertCreated();

        $this->getDashboard()->assertJsonPath('data.pending_applications_count', 1);
    }

    public function test_募集を作成するとキャッシュが破棄され一覧に出る(): void
    {
        $this->getDashboard()->assertJsonCount(0, 'data.upcoming_postings');

        $location = Location::factory()->create();
        $this->actingAsAdmin()->postJson('/api/job-postings', [
            'location_id' => $location->id, 'job_title' => 'ピッキング', 'work_date' => '2026-10-20',
            'start_time' => '09:00', 'end_time' => '17:00', 'capacity' => 3, 'hourly_wage' => 1300, 'transport_allowance' => 500,
        ])->assertCreated();

        $this->getDashboard()->assertJsonCount(1, 'data.upcoming_postings');
    }

    public function test_募集を締め切るとキャッシュが破棄される(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-05')->create();
        $this->assertSame('2026-10-01T10:00:00+09:00', $this->generatedAt());

        $this->travel(1)->minutes();
        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/close")->assertOk();

        // 締切の募集は一覧に残るので、作り直されたことは generated_at で確かめる
        $this->getDashboard()
            ->assertJsonPath('data.upcoming_postings.0.id', $posting->id)
            ->assertJsonPath('data.generated_at', '2026-10-01T10:01:00+09:00');
    }

    public function test_募集を中止するとキャッシュが破棄される(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-05')->create();
        Application::factory()->for($posting)->create();
        $this->getDashboard()
            ->assertJsonCount(1, 'data.upcoming_postings')
            ->assertJsonPath('data.pending_applications_count', 1);

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        // 中止で募集は一覧から消え、応募中の応募は却下になる
        $this->getDashboard()
            ->assertJsonCount(0, 'data.upcoming_postings')
            ->assertJsonPath('data.pending_applications_count', 0);
    }

    public function test_募集を削除してもキャッシュが破棄される(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-05')->create();
        $this->getDashboard()->assertJsonCount(1, 'data.upcoming_postings');

        // 募集の削除 API はない。Observer の deleted が効くことをモデルの削除で確かめる
        $posting->delete();

        $this->getDashboard()->assertJsonCount(0, 'data.upcoming_postings');
    }

    public function test_ロールバックした変更ではキャッシュは破棄されない(): void
    {
        $this->getDashboard()->assertJsonCount(0, 'data.upcoming_postings');

        try {
            DB::transaction(function () {
                JobPosting::factory()->onDate('2026-10-05')->create();
                // トランザクションの途中ではまだ破棄されない（コミット後に破棄する）
                $this->assertTrue(Cache::has('dashboard:'.$this->company->id));

                throw new \RuntimeException('ロールバックさせる');
            });
        } catch (\RuntimeException) {
            // 想定どおり
        }

        $this->assertTrue(Cache::has('dashboard:'.$this->company->id));
        $this->assertSame('2026-10-01T10:00:00+09:00', $this->generatedAt());
    }

    public function test_定員超過で承認できなかったときはキャッシュは破棄されない(): void
    {
        $posting = $this->postingWithConfirmed(capacity: 1, confirmed: 1);
        $application = Application::factory()->for($posting)->create();
        $this->getDashboard()->assertOk();

        $spy = Cache::spy();
        $this->actingAsAdmin()->postJson("/api/applications/{$application->id}/confirm")->assertUnprocessable();

        $spy->shouldNotHaveReceived('forget');
    }

    public function test_キャッシュは企業ごとに分かれ他社の値が混ざらない(): void
    {
        $ours = JobPosting::factory()->onDate('2026-10-05')->create();
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => JobPosting::factory()->onDate('2026-10-06')->create());
        $otherAdmin = User::factory()->for($other)->create();

        Context::forget('company_id');
        $this->getDashboard()->assertJsonPath('data.upcoming_postings.0.id', $ours->id);

        // 自社のキャッシュがあっても、他社には他社の値が返る
        Context::forget('company_id');
        $this->actingAs($otherAdmin)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonCount(1, 'data.upcoming_postings')
            ->assertJsonPath('data.upcoming_postings.0.id', $theirs->id);

        // 他社の変更で自社のキャッシュは消えない
        $this->travel(1)->minutes();
        CurrentCompany::runAs($other->id, fn () => JobPosting::factory()->onDate('2026-10-07')->create());
        $this->assertTrue(Cache::has('dashboard:'.$this->company->id));
        $this->assertFalse(Cache::has('dashboard:'.$other->id));

        Context::forget('company_id');
        $this->getDashboard()
            ->assertJsonCount(1, 'data.upcoming_postings')
            ->assertJsonPath('data.generated_at', '2026-10-01T10:00:00+09:00');
    }

    public function test_有効期限を過ぎると作り直される(): void
    {
        Config::set('myteam.dashboard_cache_seconds', 300);
        $this->getDashboard()->assertOk();

        $this->travel(299)->seconds();
        $this->assertSame('2026-10-01T10:00:00+09:00', $this->generatedAt());

        $this->travel(1)->seconds();
        $this->assertSame('2026-10-01T10:05:00+09:00', $this->generatedAt());
    }

    public function test_日付をまたいだら有効期限内でも作り直され前日の募集は消える(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(23, 58));
        $today = JobPosting::factory()->onDate('2026-10-01')->create();
        $this->getDashboard()->assertJsonPath('data.upcoming_postings.0.id', $today->id);

        // 5 分以内でも日付が変わったら古い一覧は返さない
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(0, 0));

        $this->getDashboard()
            ->assertJsonCount(0, 'data.upcoming_postings')
            ->assertJsonPath('data.generated_at', '2026-10-02T00:00:00+09:00');
    }

    public function test_forgetは指定した企業のキャッシュだけを消す(): void
    {
        $other = Company::factory()->create();
        Cache::put('dashboard:'.$this->company->id, ['x'], 300);
        Cache::put('dashboard:'.$other->id, ['y'], 300);

        app(DashboardService::class)->forget($this->company->id);

        $this->assertFalse(Cache::has('dashboard:'.$this->company->id));
        $this->assertTrue(Cache::has('dashboard:'.$other->id));
    }
}
