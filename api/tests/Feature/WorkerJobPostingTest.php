<?php

namespace Tests\Feature;

use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Models\Application;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\Worker;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsWorker;
use Tests\TestCase;

/**
 * ワーカーの募集の検索・詳細と拠点の一覧（F-30・F-31。docs/01 の 4.5 の WorkerPortalService、6.11）。
 * 今日は 2026-10-01 10:00 に固定する（「今日以降」は日付だけで比べるので、今日の 9:00 開始の募集も並ぶ）。
 * 期待値に関わる列（勤務日・時刻・職種・拠点名・時給）はファクトリの乱数を使わず、posting() で明示する。
 */
class WorkerJobPostingTest extends TestCase
{
    use ActsAsWorker;

    private Worker $worker;

    private Location $tokyo;

    private Location $osaka;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00'));

        $this->worker = Worker::factory()->registered()->create(['name' => '山田太郎']);
        $this->tokyo = Location::factory()->create(['name' => '東京倉庫']);
        $this->osaka = Location::factory()->create(['name' => '大阪倉庫']);
    }

    // ---- 一覧（F-30。GET /api/worker/job-postings） ----

    public function test_自社の募集中で今日以降の募集だけが勤務日と開始時刻の順に並ぶ(): void
    {
        $todayAfternoon = $this->posting(['work_date' => '2026-10-01', 'start_time' => '13:00']);
        // 今の時刻（10:00）より前に始まる今日の勤務も「今日以降」に入る（日付だけで比べる。docs/01 の 4.5）
        $todayMorning = $this->posting(['work_date' => '2026-10-01', 'start_time' => '09:00']);
        $later = $this->posting(['work_date' => '2026-10-03', 'start_time' => '08:00']);
        $this->posting(['work_date' => '2026-09-30']);
        $this->posting(['work_date' => '2026-10-02', 'status' => JobPostingStatus::Closed]);
        $this->posting(['work_date' => '2026-10-02', 'status' => JobPostingStatus::Cancelled]);

        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/job-postings')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$todayMorning->id, $todayAfternoon->id, $later->id])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_日付の範囲と拠点で絞り込める(): void
    {
        $this->posting(['work_date' => '2026-10-02']);
        $tokyo3 = $this->posting(['work_date' => '2026-10-03']);
        $osaka4 = $this->posting(['work_date' => '2026-10-04', 'location_id' => $this->osaka->id]);
        $this->posting(['work_date' => '2026-10-05']);
        $this->posting(['work_date' => '2026-09-30']);

        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/job-postings?date_from=2026-10-03&date_to=2026-10-04')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$tokyo3->id, $osaka4->id]);

        $this->getJson("/api/worker/job-postings?date_from=2026-10-03&date_to=2026-10-04&location_id={$this->tokyo->id}")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$tokyo3->id]);

        // 開始日に過去を入れても、過去の募集は出ない（今日以降の条件は外れない）
        $this->getJson('/api/worker/job-postings?date_from=2026-09-01&date_to=2026-10-02')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_日付の形式の誤りと開始日が終了日より後と他社の拠点は422(): void
    {
        $otherLocation = CurrentCompany::runAs(Company::factory()->create()->id, fn () => Location::factory()->create());

        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/job-postings?date_from=2026/10/03&date_to=20261004')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from', 'date_to']);

        // 開始日の形式だけが誤りなら、正しい終了日に「開始日より後」の誤りを重ねて付けない
        $this->getJson('/api/worker/job-postings?date_from=2026/10/03&date_to=2026-10-04')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from'])
            ->assertJsonMissingValidationErrors(['date_to']);

        $this->getJson('/api/worker/job-postings?date_from=2026-10-05&date_to=2026-10-04')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to'])
            ->assertJsonMissingValidationErrors(['date_from']);

        $this->getJson('/api/worker/job-postings?location_id=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location_id']);
        $this->forgetAuthenticatedUsers();

        // 他社の拠点の判定は CurrentCompany に頼るので、本物のトークンで企業を空にしてから叩く（worker ミドルウェアが企業を決める）
        $token = $this->workerToken($this->worker);
        Context::forget('company_id');

        $this->withToken($token)
            ->getJson("/api/worker/job-postings?location_id={$otherLocation->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location_id']);
        $this->forgetAuthenticatedUsers();

        // 自社の拠点なら通る（企業が逆になっていないこと）
        $this->withToken($token)
            ->getJson("/api/worker/job-postings?location_id={$this->tokyo->id}")
            ->assertOk();
    }

    public function test_残り枠と自分の応募と応募できるかが返る(): void
    {
        // 自分が応募中の募集: 定員 3・確定 1（ほかのワーカー）・応募中 1（自分）→ 残り 2。自分の応募があるので応募できない
        $applied = $this->posting(['work_date' => '2026-10-02', 'job_title' => 'ピッキング']);
        Application::factory()->confirmed()->create(['job_posting_id' => $applied->id]);
        $mine = Application::factory()->create(['job_posting_id' => $applied->id, 'worker_id' => $this->worker->id]);

        // 応募していない募集: 定員 3・確定 1・却下 1 → 残り 2（却下は数えない）。応募できる
        $open = $this->posting(['work_date' => '2026-10-03', 'job_title' => '検品', 'location_id' => $this->osaka->id,
            'start_time' => '22:00', 'end_time' => '06:00', 'hourly_wage' => 1500, 'transport_allowance' => 0]);
        Application::factory()->confirmed()->create(['job_posting_id' => $open->id]);
        Application::factory()->rejected()->create(['job_posting_id' => $open->id]);

        $response = $this->actingAsWorker($this->worker)->getJson('/api/worker/job-postings')->assertOk();

        // 契約の形（docs/01 の 6.11 の WorkerJobPostingResource）と完全に一致すること（余計な項目を出さない）
        $this->assertSame([
            'id' => $applied->id,
            'location' => ['id' => $this->tokyo->id, 'name' => '東京倉庫'],
            'job_title' => 'ピッキング',
            'work_date' => '2026-10-02',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'hourly_wage' => 1300,
            'transport_allowance' => 500,
            'capacity' => 3,
            'remaining' => 2,
            'status' => ['value' => 'open', 'label' => '募集中'],
            'my_application' => ['id' => $mine->id, 'status' => ['value' => 'applied', 'label' => '応募中']],
            'can' => ['apply' => false],
        ], $response->json('data.0'));

        $this->assertSame([
            'id' => $open->id,
            'location' => ['id' => $this->osaka->id, 'name' => '大阪倉庫'],
            'job_title' => '検品',
            'work_date' => '2026-10-03',
            'start_time' => '22:00',
            'end_time' => '06:00',
            'hourly_wage' => 1500,
            'transport_allowance' => 0,
            'capacity' => 3,
            'remaining' => 2,
            'status' => ['value' => 'open', 'label' => '募集中'],
            'my_application' => null,
            'can' => ['apply' => true],
        ], $response->json('data.1'));

        // 詳細も同じ形
        $this->getJson("/api/worker/job-postings/{$applied->id}")
            ->assertOk()
            ->assertJsonPath('data', $response->json('data.0'));
    }

    public function test_残り枠は0より小さくならない(): void
    {
        // 管理者の代行入力などで確定が定員を超えていても、残り枠は 0（docs/01 の 4.6）
        $over = $this->posting(['capacity' => 2]);
        Application::factory()->count(3)->confirmed()->create(['job_posting_id' => $over->id]);
        $full = $this->posting(['capacity' => 1, 'work_date' => '2026-10-06']);
        Application::factory()->confirmed()->create(['job_posting_id' => $full->id]);

        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/job-postings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $over->id)
            ->assertJsonPath('data.0.remaining', 0)
            ->assertJsonPath('data.0.can.apply', false)
            ->assertJsonPath('data.1.id', $full->id)
            ->assertJsonPath('data.1.remaining', 0)
            ->assertJsonPath('data.1.can.apply', false);
    }

    public function test_残り枠は確定だけを数えほかのワーカーの応募中とキャンセルと却下は数えない(): void
    {
        // 定員 2・確定 1・応募中 2・キャンセル 1・却下 1 → 残り 1（確定だけを数える。docs/01 の 4.6）。ほかのワーカーの応募は my_application に出ない
        $posting = $this->posting(['capacity' => 2]);
        Application::factory()->confirmed()->create(['job_posting_id' => $posting->id]);
        Application::factory()->count(2)->create(['job_posting_id' => $posting->id]);
        Application::factory()->cancelled()->create(['job_posting_id' => $posting->id]);
        Application::factory()->rejected()->create(['job_posting_id' => $posting->id]);

        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/job-postings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $posting->id)
            ->assertJsonPath('data.0.capacity', 2)
            ->assertJsonPath('data.0.remaining', 1)
            ->assertJsonPath('data.0.my_application', null)
            ->assertJsonPath('data.0.can.apply', true);
    }

    public function test_応募できるかの境界は今日の勤務と残り1と自分の応募の状態(): void
    {
        // 今日の勤務は開始時刻（9:00）を過ぎていても応募できる（日付だけで比べる。docs/01 の 4.5）
        $today = $this->posting(['work_date' => '2026-10-01', 'start_time' => '09:00']);
        // 残り枠がちょうど 1 なら応募できる
        $lastOne = $this->posting(['work_date' => '2026-10-02', 'capacity' => 2]);
        Application::factory()->confirmed()->create(['job_posting_id' => $lastOne->id]);
        // 自分の応募が却下・キャンセルでも「自分の応募がある」ので応募できない（UNIQUE (job_posting_id, worker_id)）
        $rejected = $this->posting(['work_date' => '2026-10-03']);
        $rejectedMine = Application::factory()->rejected()->create(['job_posting_id' => $rejected->id, 'worker_id' => $this->worker->id]);
        $cancelled = $this->posting(['work_date' => '2026-10-04']);
        $cancelledMine = Application::factory()->cancelled()->create(['job_posting_id' => $cancelled->id, 'worker_id' => $this->worker->id]);

        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/job-postings')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$today->id, $lastOne->id, $rejected->id, $cancelled->id])
            ->assertJsonPath('data.0.can.apply', true)
            ->assertJsonPath('data.1.remaining', 1)
            ->assertJsonPath('data.1.can.apply', true)
            ->assertJsonPath('data.2.my_application', ['id' => $rejectedMine->id, 'status' => ['value' => 'rejected', 'label' => '却下']])
            ->assertJsonPath('data.2.remaining', 3)
            ->assertJsonPath('data.2.can.apply', false)
            ->assertJsonPath('data.3.my_application', ['id' => $cancelledMine->id, 'status' => ['value' => 'cancelled', 'label' => 'キャンセル']])
            ->assertJsonPath('data.3.remaining', 3)
            ->assertJsonPath('data.3.can.apply', false);
    }

    public function test_日付の範囲は両端を含み片方だけでも絞り込める(): void
    {
        $d2 = $this->posting(['work_date' => '2026-10-02']);
        $d3 = $this->posting(['work_date' => '2026-10-03']);
        $d4 = $this->posting(['work_date' => '2026-10-04']);

        // 開始日 = 終了日はその日だけ（422 にならない）
        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/job-postings?date_from=2026-10-03&date_to=2026-10-03')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$d3->id]);

        $this->getJson('/api/worker/job-postings?date_from=2026-10-03')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$d3->id, $d4->id]);

        $this->getJson('/api/worker/job-postings?date_to=2026-10-03')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$d2->id, $d3->id]);

        // 終了日が昨日なら今日以降の条件と重ならず 0 件（422 ではない）
        $this->getJson('/api/worker/job-postings?date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);

        // 空の値は絞り込まない（422 にもならない）
        $this->getJson('/api/worker/job-postings?date_from=&date_to=&location_id=')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_存在しない日付は422(): void
    {
        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/job-postings?date_from=2026-02-30&date_to=2026-13-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    public function test_募集のない自社の拠点で絞ると0件(): void
    {
        $empty = Location::factory()->create(['name' => '名古屋倉庫']);
        $this->posting();

        $this->actingAsWorker($this->worker)
            ->getJson("/api/worker/job-postings?location_id={$empty->id}")
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_同じ勤務日と開始時刻の募集はid順に並ぶ(): void
    {
        $first = $this->posting(['work_date' => '2026-10-02', 'start_time' => '09:00', 'location_id' => $this->osaka->id]);
        $second = $this->posting(['work_date' => '2026-10-02', 'start_time' => '09:00']);
        $earlier = $this->posting(['work_date' => '2026-10-02', 'start_time' => '08:30']);

        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/job-postings')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$earlier->id, $first->id, $second->id]);
    }

    public function test_per_pageは0と文字列なら1で101なら100に丸める(): void
    {
        foreach (['2026-10-02', '2026-10-03'] as $date) {
            $this->posting(['work_date' => $date]);
        }

        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/job-postings?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/worker/job-postings?per_page=abc')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);

        $this->getJson('/api/worker/job-postings?per_page=101')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        $this->getJson('/api/worker/job-postings?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    // ---- 詳細（F-31。GET /api/worker/job-postings/{id}） ----

    public function test_自分の応募が却下された締切の募集も詳細を見られ締切と中止は別の状態で返る(): void
    {
        $closed = $this->posting(['status' => JobPostingStatus::Closed]);
        $mine = Application::factory()->rejected()->create(['job_posting_id' => $closed->id, 'worker_id' => $this->worker->id]);
        $cancelled = $this->posting(['status' => JobPostingStatus::Cancelled, 'work_date' => '2026-10-06']);
        Application::factory()->create(['job_posting_id' => $cancelled->id, 'worker_id' => $this->worker->id]);

        $this->actingAsWorker($this->worker)
            ->getJson("/api/worker/job-postings/{$closed->id}")
            ->assertOk()
            ->assertJsonPath('data.status', ['value' => 'closed', 'label' => '締切'])
            ->assertJsonPath('data.my_application', ['id' => $mine->id, 'status' => ['value' => 'rejected', 'label' => '却下']])
            ->assertJsonPath('data.can.apply', false);

        // 中止は締切と別の value・label（アプリが「中止」と出し分けられる）
        $this->getJson("/api/worker/job-postings/{$cancelled->id}")
            ->assertOk()
            ->assertJsonPath('data.status', ['value' => 'cancelled', 'label' => '中止'])
            ->assertJsonPath('data.can.apply', false);
    }

    public function test_募集中で今日の募集は応募がなくても詳細を見られる(): void
    {
        $today = $this->posting(['work_date' => '2026-10-01', 'start_time' => '09:00']);

        $this->actingAsWorker($this->worker)
            ->getJson("/api/worker/job-postings/{$today->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $today->id)
            ->assertJsonPath('data.my_application', null)
            ->assertJsonPath('data.can.apply', true);
    }

    public function test_深夜跨ぎの募集は勤務日と時刻をそのまま返す(): void
    {
        // 終了が開始より前でも翌日の日付や 24 時以降の表記にしない（docs/01 の 6.1 の「時刻」は "HH:MM"）
        $night = $this->posting(['work_date' => '2026-10-02', 'start_time' => '22:00:00', 'end_time' => '05:30:00']);

        $this->actingAsWorker($this->worker)
            ->getJson("/api/worker/job-postings/{$night->id}")
            ->assertOk()
            ->assertJsonPath('data.work_date', '2026-10-02')
            ->assertJsonPath('data.start_time', '22:00')
            ->assertJsonPath('data.end_time', '05:30');
    }

    public function test_自分の応募が別の募集にあっても応募のない締切の募集の詳細は404(): void
    {
        // 見える条件の「自分の応募」はその募集への応募に限る。ほかのワーカーの応募や、自分の別の募集への応募では見えない
        $closed = $this->posting(['status' => JobPostingStatus::Closed]);
        Application::factory()->create(['job_posting_id' => $closed->id]);
        $mineElsewhere = $this->posting(['work_date' => '2026-10-06']);
        Application::factory()->create(['job_posting_id' => $mineElsewhere->id, 'worker_id' => $this->worker->id]);

        $this->actingAsWorker($this->worker)
            ->getJson("/api/worker/job-postings/{$closed->id}")
            ->assertNotFound();
    }

    public function test_自分の応募がある締切と中止と過去の募集は詳細を見られる(): void
    {
        $closed = $this->posting(['status' => JobPostingStatus::Closed]);
        $closedMine = Application::factory()->create(['job_posting_id' => $closed->id, 'worker_id' => $this->worker->id]);
        $cancelled = $this->posting(['status' => JobPostingStatus::Cancelled]);
        Application::factory()->cancelled(CancelReason::Company)->create(['job_posting_id' => $cancelled->id, 'worker_id' => $this->worker->id]);
        $past = $this->posting(['work_date' => '2026-09-20']);
        Application::factory()->confirmed()->create(['job_posting_id' => $past->id, 'worker_id' => $this->worker->id]);

        $this->actingAsWorker($this->worker)
            ->getJson("/api/worker/job-postings/{$closed->id}")
            ->assertOk()
            ->assertJsonPath('data.status', ['value' => 'closed', 'label' => '締切'])
            ->assertJsonPath('data.my_application', ['id' => $closedMine->id, 'status' => ['value' => 'applied', 'label' => '応募中']])
            ->assertJsonPath('data.can.apply', false);

        $this->getJson("/api/worker/job-postings/{$cancelled->id}")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'cancelled')
            ->assertJsonPath('data.my_application.status', ['value' => 'cancelled', 'label' => 'キャンセル'])
            ->assertJsonPath('data.can.apply', false);

        // 過去の募集は確定 1（自分）で残り 2 でも、勤務日が過ぎているので応募できない
        $this->getJson("/api/worker/job-postings/{$past->id}")
            ->assertOk()
            ->assertJsonPath('data.work_date', '2026-09-20')
            ->assertJsonPath('data.remaining', 2)
            ->assertJsonPath('data.my_application.status.value', 'confirmed')
            ->assertJsonPath('data.can.apply', false);
    }

    public function test_条件を満たさない募集の詳細は404(): void
    {
        $closed = $this->posting(['status' => JobPostingStatus::Closed]);
        $cancelled = $this->posting(['status' => JobPostingStatus::Cancelled]);
        $past = $this->posting(['work_date' => '2026-09-30']);
        // ほかのワーカーの応募があっても、自分の応募がなければ見られない
        $closedWithOthers = $this->posting(['status' => JobPostingStatus::Closed]);
        Application::factory()->confirmed()->create(['job_posting_id' => $closedWithOthers->id]);

        $this->actingAsWorker($this->worker);

        foreach ([$closed->id, $cancelled->id, $past->id, $closedWithOthers->id, 999999] as $id) {
            $this->getJson("/api/worker/job-postings/{$id}")
                ->assertNotFound()
                ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);
        }
    }

    public function test_他社の募集は一覧に出ず詳細は404(): void
    {
        $own = $this->posting();
        $other = CurrentCompany::runAs(Company::factory()->create()->id, fn () => JobPosting::factory()->create([
            'work_date' => '2026-10-02',
        ]));

        // 守りは本物のトークンで確かめる（Sanctum::actingAs は guard の provider の確認を通らない。docs/01 の 5.3）。
        // 本番のリクエストは企業が空の状態で始まるので、setUp の企業を消してから叩く
        $token = $this->workerToken($this->worker);
        Context::forget('company_id');

        $this->withToken($token)
            ->getJson('/api/worker/job-postings')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$own->id]);
        $this->forgetAuthenticatedUsers();

        $this->withToken($token)
            ->getJson("/api/worker/job-postings/{$other->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);
    }

    public function test_応答にほかのワーカーの氏名と応募数が出ない(): void
    {
        $posting = $this->posting();
        $otherWorker = Worker::factory()->create(['name' => '佐藤花子']);
        Application::factory()->confirmed()->create(['job_posting_id' => $posting->id, 'worker_id' => $otherWorker->id]);
        Application::factory()->create(['job_posting_id' => $posting->id, 'worker_id' => $this->worker->id]);

        $this->actingAsWorker($this->worker);
        $list = $this->getJson('/api/worker/job-postings')->assertOk()->json();
        $detail = $this->getJson("/api/worker/job-postings/{$posting->id}")->assertOk()->json();

        foreach ([$list['data'][0], $detail['data']] as $item) {
            // JSON は日本語を \uXXXX にエスケープするので、assertDontSee ではなく、エスケープしない文字列で確かめる（testing.md）
            $this->assertStringNotContainsString('佐藤花子', json_encode($item, JSON_UNESCAPED_UNICODE));
            foreach (['applications', 'applications_count', 'confirmed_count', 'confirmed_applications_count', 'worker', 'workers'] as $key) {
                $this->assertArrayNotHasKey($key, $item);
            }
            $this->assertSame(['id', 'status'], array_keys($item['my_application']));
        }
    }

    // ---- 拠点の一覧（F-30 の絞り込みの選択肢。GET /api/worker/locations） ----

    public function test_拠点の一覧は自社の拠点だけでページなし(): void
    {
        CurrentCompany::runAs(Company::factory()->create()->id, fn () => Location::factory()->create(['name' => '他社倉庫']));

        // 本番と同じく企業が空の状態から、本物のトークンで叩く（worker ミドルウェアがワーカーの企業を決める）
        $token = $this->workerToken($this->worker);
        Context::forget('company_id');

        $this->withToken($token)
            ->getJson('/api/worker/locations')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $this->tokyo->id, 'name' => '東京倉庫'],
                ['id' => $this->osaka->id, 'name' => '大阪倉庫'],
            ]]);
    }

    // ---- ページングとクエリ数 ----

    public function test_一覧はページングされper_pageは最大100(): void
    {
        foreach (['2026-10-02', '2026-10-03', '2026-10-04'] as $date) {
            $this->posting(['work_date' => $date]);
        }

        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/job-postings?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total'], 'links']);

        $this->getJson('/api/worker/job-postings?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.work_date', '2026-10-04');

        $this->getJson('/api/worker/job-postings?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        $this->getJson('/api/worker/job-postings')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 20);
    }

    public function test_一覧のクエリ数は件数に比例しない(): void
    {
        $this->postingWithApplications('2026-10-02');
        $this->actingAsWorker($this->worker);

        $one = $this->countQueries(fn () => $this->getJson('/api/worker/job-postings')->assertOk()->assertJsonCount(1, 'data'));

        foreach (['2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07'] as $date) {
            $this->postingWithApplications($date);
        }

        $six = $this->countQueries(fn () => $this->getJson('/api/worker/job-postings')->assertOk()->assertJsonCount(6, 'data'));

        $this->assertSame($one, $six);
    }

    /**
     * 期待値に関わる列を明示した募集（既定は東京倉庫・2026-10-05 9:00-17:00・ピッキング・定員 3・時給 1,300・交通費 500・募集中）
     *
     * @param  array<string, mixed>  $attributes
     */
    private function posting(array $attributes = []): JobPosting
    {
        return JobPosting::factory()->create([
            'location_id' => $this->tokyo->id,
            'job_title' => 'ピッキング',
            'work_date' => '2026-10-05',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 3,
            'hourly_wage' => 1300,
            'transport_allowance' => 500,
            ...$attributes,
        ]);
    }

    /**
     * 自分の応募とほかのワーカーの確定がある募集（拠点も別に作る。N+1 があれば件数に比例して増える）
     */
    private function postingWithApplications(string $date): void
    {
        $posting = $this->posting(['work_date' => $date, 'location_id' => Location::factory()->create()->id]);
        Application::factory()->confirmed()->create(['job_posting_id' => $posting->id]);
        Application::factory()->create(['job_posting_id' => $posting->id, 'worker_id' => $this->worker->id]);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
