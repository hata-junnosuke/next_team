<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\User;
use App\Services\DashboardService;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * F-19 ダッシュボードのキャッシュ（docs/01 の非同期の契約、async-rules の「キャッシュ」）の補強。
 * DashboardTest のキャッシュの節で足りない分（全ての変更経路、ネストしたトランザクション、企業の分離、
 * 設定 0 以下、日付の終わりの直前）をここに置く。
 *
 * 「キャッシュが作り直されたか」は generated_at（キャッシュした時刻）と Cache::has() で見る。
 * 時刻は 2026-10-01 10:00 に固定し、変更の前に 1 分進めて、作り直されたら 10:01 になるようにする。
 * キャッシュ（Rails の Rails.cache に相当）: https://readouble.com/laravel/13.x/ja/cache.html
 */
class DashboardCacheTest extends TestCase
{
    private const CACHED_AT = '2026-10-01T10:00:00+09:00';

    private const REBUILT_AT = '2026-10-01T10:01:00+09:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
    }

    private function getDashboard(): TestResponse
    {
        return $this->actingAsAdmin()->getJson('/api/dashboard')->assertOk();
    }

    private function ourKey(): string
    {
        return 'dashboard:'.$this->company->id;
    }

    /**
     * 10:00 にキャッシュを作り、1 分進める。この後に取得して generated_at が 10:01 なら作り直された
     */
    private function warmCacheAndAdvance(): void
    {
        $this->getDashboard()->assertJsonPath('data.generated_at', self::CACHED_AT);
        $this->assertTrue(Cache::has($this->ourKey()));
        $this->travel(1)->minutes();
    }

    private function postingPayload(Location $location, array $overrides = []): array
    {
        return [
            'location_id' => $location->id, 'job_title' => 'ピッキング', 'work_date' => '2026-10-05',
            'start_time' => '09:00', 'end_time' => '17:00', 'capacity' => 3, 'hourly_wage' => 1300, 'transport_allowance' => 500,
            ...$overrides,
        ];
    }

    // ---- API 経由の変更経路（Observer が経路によらず破棄する） ----

    public function test_却下するとキャッシュが破棄され未処理の件数が減る(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-05')->create();
        $application = Application::factory()->for($posting)->create();
        $this->getDashboard()->assertJsonPath('data.pending_applications_count', 1);
        $this->travel(1)->minutes();

        $this->actingAsAdmin()->postJson("/api/applications/{$application->id}/reject")->assertOk();

        $this->getDashboard()
            ->assertJsonPath('data.pending_applications_count', 0)
            ->assertJsonPath('data.generated_at', self::REBUILT_AT);
    }

    public function test_確定をキャンセルするとキャッシュが破棄され確定数と充足率が減る(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-05')->create(['capacity' => 2]);
        $application = Application::factory()->for($posting)->confirmed()->create();
        $this->getDashboard()
            ->assertJsonPath('data.upcoming_postings.0.confirmed_count', 1)
            ->assertJsonPath('data.upcoming_postings.0.fill_rate', 50);
        $this->travel(1)->minutes();

        $this->actingAsAdmin()->postJson("/api/applications/{$application->id}/cancel", ['reason' => 'worker'])->assertOk();

        $this->getDashboard()
            ->assertJsonPath('data.upcoming_postings.0.confirmed_count', 0)
            ->assertJsonPath('data.upcoming_postings.0.fill_rate', 0)
            ->assertJsonPath('data.generated_at', self::REBUILT_AT);
    }

    public function test_募集を複製するとキャッシュが破棄され複製した募集が一覧に出る(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-05')->create();
        $this->getDashboard()->assertJsonCount(1, 'data.upcoming_postings');
        $this->travel(1)->minutes();

        $copyId = $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/duplicate", ['work_date' => '2026-10-06'])
            ->assertCreated()
            ->json('data.id');

        $this->getDashboard()
            ->assertJsonCount(2, 'data.upcoming_postings')
            ->assertJsonPath('data.upcoming_postings.1.id', $copyId)
            ->assertJsonPath('data.generated_at', self::REBUILT_AT);
    }

    public function test_募集を編集するとキャッシュが破棄され職種と定員と充足率が更新される(): void
    {
        $location = Location::factory()->create();
        $posting = JobPosting::factory()->onDate('2026-10-05')->for($location)->create(['capacity' => 4, 'job_title' => 'ピッキング']);
        Application::factory()->for($posting)->confirmed()->create();
        $this->getDashboard()->assertJsonPath('data.upcoming_postings.0.fill_rate', 25);
        $this->travel(1)->minutes();

        $this->actingAsAdmin()
            ->putJson("/api/job-postings/{$posting->id}", $this->postingPayload($location, ['job_title' => '検品', 'capacity' => 2]))
            ->assertOk();

        $this->getDashboard()
            ->assertJsonPath('data.upcoming_postings.0.job_title', '検品')
            ->assertJsonPath('data.upcoming_postings.0.capacity', 2)
            ->assertJsonPath('data.upcoming_postings.0.fill_rate', 50)
            ->assertJsonPath('data.generated_at', self::REBUILT_AT);
    }

    public function test_募集の勤務日を過去に編集すると一覧から消える(): void
    {
        $location = Location::factory()->create();
        $posting = JobPosting::factory()->onDate('2026-10-05')->for($location)->create();
        $this->getDashboard()->assertJsonCount(1, 'data.upcoming_postings');

        $this->actingAsAdmin()
            ->putJson("/api/job-postings/{$posting->id}", $this->postingPayload($location, ['work_date' => '2026-09-30']))
            ->assertOk();

        $this->getDashboard()->assertJsonCount(0, 'data.upcoming_postings');
    }

    public function test_勤怠を入力してもダッシュボードのキャッシュは破棄されず値も変わらない(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-01')->create(['capacity' => 2]);
        $application = Application::factory()->for($posting)->confirmed()->create();
        $before = $this->getDashboard()->json('data');
        $this->travel(1)->minutes();

        $this->actingAsAdmin()
            ->putJson("/api/applications/{$application->id}/attendance", ['clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60])
            ->assertSuccessful();

        // 勤怠（Attendance）は Observer の対象外。応募の行も書き換えない
        $this->assertTrue(Cache::has($this->ourKey()));
        $this->getDashboard()->assertExactJson(['data' => $before]);
    }

    public function test_応募を削除してもキャッシュが破棄される(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-05')->create();
        $application = Application::factory()->for($posting)->create();
        $this->getDashboard()->assertJsonPath('data.pending_applications_count', 1);

        // 応募の削除 API はない。Observer の deleted が Application にも付いていることをモデルの削除で確かめる
        $application->delete();

        $this->assertFalse(Cache::has($this->ourKey()));
        $this->getDashboard()->assertJsonPath('data.pending_applications_count', 0);
    }

    // ---- 失敗した操作では破棄しない（422） ----

    public function test_状態遷移違反で422のときはキャッシュは破棄されない(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-05')->closed()->create();
        $this->warmCacheAndAdvance();

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/close")->assertUnprocessable();

        $this->assertTrue(Cache::has($this->ourKey()));
        $this->getDashboard()->assertJsonPath('data.generated_at', self::CACHED_AT);
    }

    public function test_中止した募集の編集が422のときはキャッシュは破棄されない(): void
    {
        $location = Location::factory()->create();
        $posting = JobPosting::factory()->onDate('2026-10-05')->for($location)->cancelled()->create();
        $this->warmCacheAndAdvance();

        $this->actingAsAdmin()
            ->putJson("/api/job-postings/{$posting->id}", $this->postingPayload($location))
            ->assertUnprocessable();

        $this->assertTrue(Cache::has($this->ourKey()));
        $this->getDashboard()->assertJsonPath('data.generated_at', self::CACHED_AT);
    }

    public function test_入力の検証で422のときはキャッシュは破棄されない(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-05')->create();
        $this->warmCacheAndAdvance();

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/duplicate", ['work_date' => '2026-10-05'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_date']);

        $this->getDashboard()->assertJsonPath('data.generated_at', self::CACHED_AT);
    }

    // ---- コミット後にだけ破棄する（ShouldHandleEventsAfterCommit） ----

    public function test_ネストしたトランザクションでは外側のコミット後に破棄される(): void
    {
        $this->warmCacheAndAdvance();

        DB::transaction(function () {
            DB::transaction(function () {
                JobPosting::factory()->onDate('2026-10-05')->create();
            });
            // 内側（セーブポイント）がコミットしても、外側が終わるまでは破棄しない
            $this->assertTrue(Cache::has($this->ourKey()));
        });

        $this->assertFalse(Cache::has($this->ourKey()));
        $this->getDashboard()
            ->assertJsonCount(1, 'data.upcoming_postings')
            ->assertJsonPath('data.generated_at', self::REBUILT_AT);
    }

    public function test_内側のトランザクションだけロールバックした変更では破棄されない(): void
    {
        $this->warmCacheAndAdvance();

        DB::transaction(function () {
            try {
                DB::transaction(function () {
                    JobPosting::factory()->onDate('2026-10-05')->create();

                    throw new \RuntimeException('内側だけロールバックさせる');
                });
            } catch (\RuntimeException) {
                // 想定どおり。外側は続けてコミットする
            }
        });

        $this->assertTrue(Cache::has($this->ourKey()));
        $this->getDashboard()
            ->assertJsonCount(0, 'data.upcoming_postings')
            ->assertJsonPath('data.generated_at', self::CACHED_AT);
    }

    public function test_外側のトランザクションがロールバックしたら内側でコミットした変更でも破棄されない(): void
    {
        $this->warmCacheAndAdvance();

        try {
            DB::transaction(function () {
                DB::transaction(function () {
                    JobPosting::factory()->onDate('2026-10-05')->create();
                });

                throw new \RuntimeException('外側をロールバックさせる');
            });
        } catch (\RuntimeException) {
            // 想定どおり
        }

        $this->assertTrue(Cache::has($this->ourKey()));
        $this->getDashboard()->assertJsonPath('data.generated_at', self::CACHED_AT);
    }

    // ---- 企業ごとの分離（キーに企業ID。security.md） ----

    public function test_contextに自社が残っていても他社の管理者には他社の値が返る(): void
    {
        JobPosting::factory()->onDate('2026-10-05')->create();
        $this->getDashboard()->assertJsonCount(1, 'data.upcoming_postings');

        $other = Company::factory()->create();
        $otherAdmin = User::factory()->for($other)->create();

        // Context::forget しない（自社の company_id が残っている）。company ミドルウェアがログインした管理者の企業で上書きすること
        $this->assertSame($this->company->id, CurrentCompany::id());
        $this->actingAs($otherAdmin)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonCount(0, 'data.upcoming_postings')
            ->assertJsonPath('data.pending_applications_count', 0);

        $this->assertTrue(Cache::has('dashboard:'.$other->id));
    }

    public function test_run_asで別の企業として取得すると別のキーで集計し自社のキャッシュは使わない(): void
    {
        $ours = JobPosting::factory()->onDate('2026-10-05')->create();
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => JobPosting::factory()->onDate('2026-10-06')->create());
        $service = app(DashboardService::class);

        $this->assertSame($ours->id, $service->summary()['upcoming_postings'][0]['id']);

        $theirSummary = CurrentCompany::runAs($other->id, fn () => $service->summary());
        $this->assertCount(1, $theirSummary['upcoming_postings']);
        $this->assertSame($theirs->id, $theirSummary['upcoming_postings'][0]['id']);

        // runAs の後は自社に戻り、自社の値（キャッシュ）が返る
        $this->assertSame($ours->id, $service->summary()['upcoming_postings'][0]['id']);
        $this->assertSame($ours->id, Cache::get($this->ourKey())['upcoming_postings'][0]['id']);
        $this->assertSame($theirs->id, Cache::get('dashboard:'.$other->id)['upcoming_postings'][0]['id']);
    }

    public function test_変更したモデルの持ち主の企業のキャッシュだけが消える(): void
    {
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => JobPosting::factory()->onDate('2026-10-06')->create());
        CurrentCompany::runAs($other->id, fn () => app(DashboardService::class)->summary());
        $this->getDashboard();
        $this->assertTrue(Cache::has('dashboard:'.$other->id));

        // 自社として動いている最中に他社の行が変わっても、消すのは CurrentCompany ではなくモデルの company_id の側
        // （グローバルスコープを外すのは、他社の行を読むため）
        $model = JobPosting::withoutGlobalScopes()->findOrFail($theirs->id);
        $model->forceFill(['job_title' => '他社で変更'])->save();

        $this->assertFalse(Cache::has('dashboard:'.$other->id));
        $this->assertTrue(Cache::has($this->ourKey()));
    }

    public function test_他社の応募の承認で自社のキャッシュは消えない(): void
    {
        $other = Company::factory()->create();
        $otherAdmin = User::factory()->for($other)->create();
        $theirApplication = CurrentCompany::runAs($other->id, function () {
            $posting = JobPosting::factory()->onDate('2026-10-06')->create();

            return Application::factory()->for($posting)->create();
        });
        $this->warmCacheAndAdvance();

        $this->actingAs($otherAdmin)->postJson("/api/applications/{$theirApplication->id}/confirm")->assertOk();

        $this->assertTrue(Cache::has($this->ourKey()));
        Context::forget('company_id');
        $this->getDashboard()->assertJsonPath('data.generated_at', self::CACHED_AT);
    }

    public function test_未ログインは401でキャッシュも作らない(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();

        $this->assertFalse(Cache::has($this->ourKey()));
    }

    // ---- 有効期限と設定 ----

    public function test_キャッシュの秒数が0ならキャッシュせず毎回集計する(): void
    {
        Config::set('myteam.dashboard_cache_seconds', 0);

        $this->getDashboard()->assertJsonPath('data.generated_at', self::CACHED_AT);
        $this->assertFalse(Cache::has($this->ourKey()));

        $this->travel(1)->seconds();
        $this->getDashboard()->assertJsonPath('data.generated_at', '2026-10-01T10:00:01+09:00');
    }

    public function test_キャッシュの秒数が負ならキャッシュせず毎回集計する(): void
    {
        Config::set('myteam.dashboard_cache_seconds', -60);

        $this->getDashboard();
        $this->assertFalse(Cache::has($this->ourKey()));

        JobPosting::factory()->onDate('2026-10-05')->create();
        $this->travel(1)->seconds();
        $this->getDashboard()
            ->assertJsonCount(1, 'data.upcoming_postings')
            ->assertJsonPath('data.generated_at', '2026-10-01T10:00:01+09:00');
    }

    public function test_日付の終わりの直前まではキャッシュから返る(): void
    {
        Config::set('myteam.dashboard_cache_seconds', 300);
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(23, 57));
        $this->getDashboard()->assertJsonPath('data.generated_at', '2026-10-01T23:57:00+09:00');

        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(23, 59, 59));
        $this->getDashboard()->assertJsonPath('data.generated_at', '2026-10-01T23:57:00+09:00');

        // 設定の 5 分（0:02）より先に、翌日 0:00 で切れる
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(0, 0));
        $this->getDashboard()->assertJsonPath('data.generated_at', '2026-10-02T00:00:00+09:00');
    }

    public function test_generated_atは東京時間の_is_o8601でキャッシュから返っても変わらない(): void
    {
        $first = $this->getDashboard()->json('data.generated_at');
        $this->travel(4)->minutes();

        $this->assertSame(self::CACHED_AT, $first);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+09:00$/', $first);
        $this->getDashboard()
            ->assertJsonStructure(['data' => ['upcoming_postings', 'pending_applications_count', 'generated_at']])
            ->assertJsonPath('data.generated_at', $first);
    }

    public function test_拠点の名前を変えるとダッシュボードの拠点名も更新される(): void
    {
        // location_name は拠点のテーブルから読むので、拠点の変更でもキャッシュを破棄する（リードの決定）
        $location = Location::factory()->create(['name' => '東京倉庫']);
        JobPosting::factory()->onDate('2026-10-05')->for($location)->create();
        $this->getDashboard()->assertJsonPath('data.upcoming_postings.0.location_name', '東京倉庫');
        $this->travel(1)->minutes();

        $this->actingAsAdmin()->putJson("/api/locations/{$location->id}", ['name' => '大阪倉庫'])->assertOk();

        $this->getDashboard()
            ->assertJsonPath('data.upcoming_postings.0.location_name', '大阪倉庫')
            ->assertJsonPath('data.generated_at', self::REBUILT_AT);
    }

    public function test_company_idを読んでいないモデルを保存しても他のキャッシュは消さず例外を報告する(): void
    {
        Exceptions::fake();
        $posting = JobPosting::factory()->onDate('2026-10-05')->create();
        $this->warmCacheAndAdvance();

        // 列を絞って読むと company_id が null のモデルになる。そのまま dashboard:0 を消して終わらせず、気づけるように報告する
        $partial = JobPosting::query()->select(['id', 'job_title'])->findOrFail($posting->id);
        $partial->forceFill(['job_title' => '列を絞って変更'])->save();

        Exceptions::assertReported(fn (\LogicException $e) => str_contains($e->getMessage(), 'company_id'));
        $this->assertTrue(Cache::has($this->ourKey()));
    }
}
