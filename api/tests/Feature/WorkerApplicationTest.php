<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Http\Middleware\AuthenticateWorker;
use App\Models\Application;
use App\Models\Attendance;
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
 * ワーカー本人の応募と自分の応募の一覧（F-32・F-33。docs/01 の 4.5 の ApplicationService::applyAsWorker()、6.11）。
 * 今日は 2026-10-01 10:00 に固定する（勤務日は日付だけで比べるので、今日の 9:00 開始の募集にも応募できる）。
 * 期待値に関わる列（勤務日・時刻・職種・拠点名・定員）はファクトリの乱数を使わず、posting() で明示する。
 */
class WorkerApplicationTest extends TestCase
{
    use ActsAsWorker;

    private Worker $worker;

    private Location $tokyo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00'));

        $this->worker = Worker::factory()->registered()->create(['name' => '山田太郎']);
        $this->tokyo = Location::factory()->create(['name' => '東京倉庫']);
    }

    // ---- 応募（F-32。POST /api/worker/job-postings/{job_posting}/applications） ----

    public function test_募集に応募でき管理者の応募一覧に応募中で出る(): void
    {
        $posting = $this->posting();

        $response = $this->actingAsWorker($this->worker)
            ->postJson("/api/worker/job-postings/{$posting->id}/applications")
            ->assertCreated();

        $application = Application::query()->sole();
        $response->assertExactJson(['data' => [
            'id' => $application->id,
            'job_posting' => [
                'id' => $posting->id,
                'job_title' => 'ピッキング',
                'work_date' => '2026-10-05',
                'start_time' => '09:00',
                'end_time' => '17:00',
                'location' => ['id' => $this->tokyo->id, 'name' => '東京倉庫'],
            ],
            'status' => ['value' => 'applied', 'label' => '応募中'],
            'applied_at' => CarbonImmutable::now()->toIso8601String(),
            'confirmed_at' => null,
            'confirmed_hourly_wage' => null,
            'confirmed_transport_allowance' => null,
            'cancel_reason' => null,
            'cancelled_at' => null,
        ]]);
        $this->assertSame($this->worker->id, $application->worker_id);
        $this->assertSame($this->company->id, $application->company_id);

        // 管理者の応募一覧（F-10）に応募中で出る（前のリクエストのワーカーを guard に残さない）
        $this->switchToAdmin()
            ->getJson("/api/job-postings/{$posting->id}/applications")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$application->id])
            ->assertJsonPath('data.0.worker.name', '山田太郎')
            ->assertJsonPath('data.0.status', ['value' => 'applied', 'label' => '応募中']);
    }

    public function test_残り枠が0なら応募できない(): void
    {
        $posting = $this->posting(['capacity' => 1]);
        Application::factory()->confirmed()->create(['job_posting_id' => $posting->id]);

        $this->assertRejected($posting, '残り枠がないため応募できません。');
    }

    public function test_締切の募集には応募できない(): void
    {
        $posting = $this->posting(['status' => JobPostingStatus::Closed]);

        $this->assertRejected($posting, '募集が締め切られているため応募できません。');
    }

    public function test_中止の募集には応募できない(): void
    {
        $posting = $this->posting(['status' => JobPostingStatus::Cancelled]);

        $this->assertRejected($posting, '募集が中止されたため応募できません。');
    }

    public function test_勤務日が過ぎた募集には応募できない(): void
    {
        $posting = $this->posting(['work_date' => '2026-09-30']);

        $this->assertRejected($posting, '勤務日が過ぎた募集には応募できません。');
    }

    public function test_今日の勤務は開始時刻を過ぎても応募できる(): void
    {
        // 今は 10:00。9:00 開始の今日の勤務にも応募できる（日付だけで比べる。docs/01 の 4.5）
        $posting = $this->posting(['work_date' => '2026-10-01', 'start_time' => '09:00']);

        $this->actingAsWorker($this->worker)
            ->postJson("/api/worker/job-postings/{$posting->id}/applications")
            ->assertCreated()
            ->assertJsonPath('data.job_posting.work_date', '2026-10-01');

        $this->assertDatabaseCount('applications', 1);
    }

    public function test_同じ募集に二重に応募できない(): void
    {
        $posting = $this->posting();

        $this->actingAsWorker($this->worker)
            ->postJson("/api/worker/job-postings/{$posting->id}/applications")
            ->assertCreated();

        $this->assertRejected($posting, 'この募集にはすでに応募しています。', expectedCount: 1);
    }

    public function test_同時の応募でunique違反になっても二重の422(): void
    {
        $posting = $this->posting();

        // exists() の確認と INSERT の間に、同じワーカーの別のリクエスト（2 回押し）が同じ応募を入れた状況を作る
        Application::creating(function () use ($posting) {
            DB::table('applications')->insert([
                'company_id' => $this->company->id,
                'job_posting_id' => $posting->id,
                'worker_id' => $this->worker->id,
                'status' => 'applied',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAsWorker($this->worker)
            ->postJson("/api/worker/job-postings/{$posting->id}/applications")
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'この募集にはすでに応募しています。', 'errors' => []]);

        // フックで入れた行も同じトランザクションの中なので、ロールバックで消える（作りかけの行を残さない）
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_応募の422はerrorsが空(): void
    {
        // 5 つの拒否はどれも errors が空（ワーカーの画面には worker_id の入力欄がない。docs/01 の 6.11）
        $full = $this->posting(['capacity' => 1]);
        Application::factory()->confirmed()->create(['job_posting_id' => $full->id]);
        $applied = $this->posting();
        Application::factory()->create(['job_posting_id' => $applied->id, 'worker_id' => $this->worker->id]);

        $cases = [
            [$full, '残り枠がないため応募できません。'],
            [$this->posting(['status' => JobPostingStatus::Closed]), '募集が締め切られているため応募できません。'],
            [$this->posting(['status' => JobPostingStatus::Cancelled]), '募集が中止されたため応募できません。'],
            [$this->posting(['work_date' => '2026-09-30']), '勤務日が過ぎた募集には応募できません。'],
            [$applied, 'この募集にはすでに応募しています。'],
        ];

        $this->actingAsWorker($this->worker);
        foreach ($cases as [$posting, $message]) {
            $this->postJson("/api/worker/job-postings/{$posting->id}/applications")
                ->assertUnprocessable()
                ->assertExactJson(['message' => $message, 'errors' => []]);
        }
    }

    public function test_残り枠が0でも管理者の代行入力はできる(): void
    {
        // 残り枠 0 で拒否するのはワーカー本人の応募（F-32）だけ。管理者の代行入力（F-09）には課さない（要件 4.8）
        $posting = $this->posting(['capacity' => 1]);
        Application::factory()->confirmed()->create(['job_posting_id' => $posting->id]);

        $this->assertRejected($posting, '残り枠がないため応募できません。');

        $this->switchToAdmin()
            ->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => $this->worker->id])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'applied');

        $this->assertDatabaseHas('applications', ['job_posting_id' => $posting->id, 'worker_id' => $this->worker->id]);
    }

    public function test_無効にされたワーカーの応募は401で応募は作られない(): void
    {
        $posting = $this->posting();
        // 守りは本物のトークンで確かめる（Sanctum::actingAs は guard の provider の確認を通らない。docs/01 の 5.3）
        $token = $this->workerToken($this->worker);
        $this->worker->update(['is_active' => false]);
        Context::forget('company_id');

        $this->withToken($token)
            ->postJson("/api/worker/job-postings/{$posting->id}/applications")
            ->assertUnauthorized()
            ->assertExactJson(['message' => AuthenticateWorker::DISABLED_MESSAGE, 'errors' => []]);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_ミドルウェアを通った後に無効にされたワーカーの応募は422で応募は作られない(): void
    {
        $posting = $this->posting();

        // worker ミドルウェアを通った後、サービスが募集をロックして読み直す時（トランザクションの中）に無効にされた状況を作る。
        // ルートモデルバインディングの読み込みはトランザクションの外なので、そこでは無効にしない
        JobPosting::retrieved(function () {
            if (DB::transactionLevel() > 0) {
                DB::table('workers')->where('id', $this->worker->id)->update(['is_active' => false]);
            }
        });

        $this->actingAsWorker($this->worker)
            ->postJson("/api/worker/job-postings/{$posting->id}/applications")
            ->assertUnprocessable()
            ->assertExactJson(['message' => AuthenticateWorker::DISABLED_MESSAGE, 'errors' => []]);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_本文のworker_idは無視され自分の応募が作られる(): void
    {
        $posting = $this->posting();
        $other = Worker::factory()->registered()->create(['name' => '佐藤花子']);

        $this->actingAsWorker($this->worker)
            ->postJson("/api/worker/job-postings/{$posting->id}/applications", ['worker_id' => $other->id])
            ->assertCreated();

        $application = Application::query()->sole();
        $this->assertSame($this->worker->id, $application->worker_id);
        $this->assertSame($posting->id, $application->job_posting_id);
    }

    public function test_他社の募集への応募は404(): void
    {
        $other = CurrentCompany::runAs(Company::factory()->create()->id, fn () => JobPosting::factory()->create([
            'work_date' => '2026-10-05',
            'capacity' => 3,
        ]));

        // 本番のリクエストは企業が空の状態で始まるので、setUp の企業を消してから本物のトークンで叩く
        $token = $this->workerToken($this->worker);
        Context::forget('company_id');

        $this->withToken($token)
            ->postJson("/api/worker/job-postings/{$other->id}/applications")
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_存在しない募集への応募は404(): void
    {
        $this->actingAsWorker($this->worker)
            ->postJson('/api/worker/job-postings/999999/applications')
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);

        $this->assertDatabaseCount('applications', 0);
    }

    // ---- 判定の順の境界（docs/01 の 4.5: 無効 → 締切・中止 → 勤務日 → 二重 → 残り枠） ----

    public function test_締切で勤務日も過ぎた募集は締切の文言(): void
    {
        $posting = $this->posting(['status' => JobPostingStatus::Closed, 'work_date' => '2026-09-30']);

        $this->assertRejected($posting, '募集が締め切られているため応募できません。');
    }

    public function test_中止で勤務日も過ぎた募集は中止の文言(): void
    {
        $posting = $this->posting(['status' => JobPostingStatus::Cancelled, 'work_date' => '2026-09-30']);

        $this->assertRejected($posting, '募集が中止されたため応募できません。');
    }

    public function test_締切の募集に応募済みなら締切の文言(): void
    {
        $posting = $this->posting(['status' => JobPostingStatus::Closed]);
        Application::factory()->create(['job_posting_id' => $posting->id, 'worker_id' => $this->worker->id]);

        $this->assertRejected($posting, '募集が締め切られているため応募できません。', expectedCount: 1);
    }

    public function test_勤務日が過ぎて残り枠も0なら勤務日の文言(): void
    {
        $posting = $this->posting(['work_date' => '2026-09-30', 'capacity' => 1]);
        Application::factory()->confirmed()->create(['job_posting_id' => $posting->id]);

        $this->assertRejected($posting, '勤務日が過ぎた募集には応募できません。');
    }

    public function test_自分の確定で満員の募集にもう一度応募すると二重の文言(): void
    {
        // 残り枠 0 の原因が自分の確定なら「残り枠がない」ではなく「すでに応募しています」を出す（二重を先に見る）
        $posting = $this->posting(['capacity' => 1]);
        Application::factory()->confirmed()->create(['job_posting_id' => $posting->id, 'worker_id' => $this->worker->id]);

        $this->assertRejected($posting, 'この募集にはすでに応募しています。', expectedCount: 1);
    }

    // ---- 残り枠は確定だけを数える（docs/01 の 4.6） ----

    public function test_応募中が定員を超えていても確定が定員未満なら応募できる(): void
    {
        $posting = $this->posting(['capacity' => 2]);
        Application::factory()->confirmed()->create(['job_posting_id' => $posting->id]);
        Application::factory()->count(3)->create(['job_posting_id' => $posting->id]);

        $this->actingAsWorker($this->worker)
            ->postJson("/api/worker/job-postings/{$posting->id}/applications")
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'applied');

        $this->assertDatabaseHas('applications', ['job_posting_id' => $posting->id, 'worker_id' => $this->worker->id]);
    }

    public function test_却下とキャンセルの応募は残り枠を減らさない(): void
    {
        $posting = $this->posting(['capacity' => 1]);
        Application::factory()->rejected()->create(['job_posting_id' => $posting->id]);
        Application::factory()->cancelled()->create(['job_posting_id' => $posting->id]);

        $this->actingAsWorker($this->worker)
            ->postJson("/api/worker/job-postings/{$posting->id}/applications")
            ->assertCreated();

        $this->assertSame(1, Application::query()->where('worker_id', $this->worker->id)->count());
    }

    // ---- 却下・キャンセルの後の再応募（UNIQUE (job_posting_id, worker_id)。docs/01 の 3 章） ----

    public function test_却下された募集にもう一度応募すると二重の422(): void
    {
        $posting = $this->posting();
        $rejected = Application::factory()->rejected()->create(['job_posting_id' => $posting->id, 'worker_id' => $this->worker->id]);

        $this->assertRejected($posting, 'この募集にはすでに応募しています。', expectedCount: 1);
        $this->assertSame('rejected', $rejected->fresh()->status->value);
    }

    public function test_キャンセルされた募集にもう一度応募すると二重の422(): void
    {
        $posting = $this->posting();
        $cancelled = Application::factory()->cancelled()->create(['job_posting_id' => $posting->id, 'worker_id' => $this->worker->id]);

        $this->assertRejected($posting, 'この募集にはすでに応募しています。', expectedCount: 1);
        $this->assertSame('cancelled', $cancelled->fresh()->status->value);
    }

    // ---- 応答の形と、管理者の代行入力（apply()）が変わっていないこと ----

    public function test_応募の応答に勤怠と報酬とワーカーの氏名と可否が出ない(): void
    {
        $posting = $this->posting();

        $response = $this->actingAsWorker($this->worker)
            ->postJson("/api/worker/job-postings/{$posting->id}/applications")
            ->assertCreated()
            ->assertJsonMissingPath('data.worker')
            ->assertJsonMissingPath('data.attendance')
            ->assertJsonMissingPath('data.payroll')
            ->assertJsonMissingPath('data.can')
            ->assertJsonMissingPath('data.job_posting.hourly_wage')
            ->assertJsonMissingPath('data.job_posting.applications_count');

        // JSON は日本語を \uXXXX にエスケープするので、assertDontSee ではなく復号した本文で確かめる（testing.md）
        $this->assertStringNotContainsString('山田太郎', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        // applied_at は ISO 8601（タイムゾーンつき）
        $this->assertSame('2026-10-01T10:00:00+09:00', $response->json('data.applied_at'));
    }

    public function test_勤務日が過ぎた募集でも管理者の代行入力はできる(): void
    {
        // 勤務日の判定はワーカー本人の応募（applyAsWorker()）だけ。共有した行を作る処理に漏れていないこと
        $posting = $this->posting(['work_date' => '2026-09-30']);

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => $this->worker->id])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'applied');
    }

    public function test_管理者の代行入力の文言とerrors_worker_idは変わらない(): void
    {
        $posting = $this->posting();
        Application::factory()->create(['job_posting_id' => $posting->id, 'worker_id' => $this->worker->id]);
        $closed = $this->posting(['status' => JobPostingStatus::Closed]);

        $this->actingAsAdmin();
        $this->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => $this->worker->id])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'このワーカーはすでにこの募集に応募しています。',
                'errors' => ['worker_id' => ['このワーカーはすでにこの募集に応募しています。']],
            ]);
        $this->postJson("/api/job-postings/{$closed->id}/applications", ['worker_id' => $this->worker->id])
            ->assertUnprocessable()
            ->assertExactJson(['message' => '締め切られた募集には応募できません。', 'errors' => []]);
    }

    // ---- 自分の応募の一覧（F-33。GET /api/worker/applications） ----

    public function test_自分の応募だけが勤務日の順に並ぶ(): void
    {
        // 作る順と並びの順をずらす。勤務日 → 開始時刻 → 応募の ID の昇順。勤務日が過ぎた応募も自分のものなら出る
        $late = $this->application(['work_date' => '2026-10-07']);
        $sameDayAfternoon = $this->application(['work_date' => '2026-10-05', 'start_time' => '13:00', 'end_time' => '18:00']);
        $sameDayMorning = $this->application(['work_date' => '2026-10-05', 'start_time' => '09:00']);
        $sameDayMorning2 = $this->application(['work_date' => '2026-10-05', 'start_time' => '09:00']);
        $past = $this->application(['work_date' => '2026-09-20']);

        // 同じ企業のほかのワーカーの応募（同じ募集にも）と、他社のワーカーの応募は出ない
        $other = Worker::factory()->registered()->create(['name' => '佐藤花子']);
        Application::factory()->create(['job_posting_id' => $late->job_posting_id, 'worker_id' => $other->id]);
        Application::factory()->create(['job_posting_id' => $this->posting(['work_date' => '2026-10-01'])->id, 'worker_id' => $other->id]);
        CurrentCompany::runAs(Company::factory()->create()->id, fn () => Application::factory()->create());

        // 他社の分離は本物のトークンで、setUp の企業を消してから確かめる（本番のリクエストは企業が空で始まる）
        $token = $this->workerToken($this->worker);
        Context::forget('company_id');

        $this->withToken($token)
            ->getJson('/api/worker/applications')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$past->id, $sameDayMorning->id, $sameDayMorning2->id, $sameDayAfternoon->id, $late->id])
            ->assertJsonPath('meta.total', 5);
    }

    public function test_状態で絞り込める(): void
    {
        $applied = $this->application();
        $confirmed = $this->application(state: ApplicationStatus::Confirmed);
        $rejected = $this->application(state: ApplicationStatus::Rejected);
        $cancelled = $this->application(state: ApplicationStatus::Cancelled);
        // ほかのワーカーの確定は、確定で絞っても出ない
        Application::factory()->confirmed()->create(['job_posting_id' => $confirmed->job_posting_id]);

        $expected = [
            ApplicationStatus::Applied->value => $applied->id,
            ApplicationStatus::Confirmed->value => $confirmed->id,
            ApplicationStatus::Rejected->value => $rejected->id,
            ApplicationStatus::Cancelled->value => $cancelled->id,
        ];

        $this->actingAsWorker($this->worker);
        foreach (ApplicationStatus::cases() as $status) {
            $this->getJson("/api/worker/applications?status={$status->value}")
                ->assertOk()
                ->assertJsonPath('data.*.id', [$expected[$status->value]])
                ->assertJsonPath('data.0.status', ['value' => $status->value, 'label' => $status->label()]);
        }
    }

    public function test_状態が決められた値でなければ422で空なら絞り込まない(): void
    {
        $applied = $this->application(['work_date' => '2026-10-05']);
        $confirmed = $this->application(['work_date' => '2026-10-06'], state: ApplicationStatus::Confirmed);

        $this->actingAsWorker($this->worker);
        // 決められた値（applied / confirmed / rejected / cancelled）以外。大文字は別の値として扱う。
        // 配列（status[]=applied）も Enum の値ではないので 422（複数の状態での絞り込みは契約にない）
        $queries = [
            'status=unknown',
            'status=withdrawn',
            'status=Applied',
            'status=APPLIED',
            'status[]=applied',
            'status[]=applied&status[]=confirmed',
        ];
        foreach ($queries as $query) {
            $this->getJson("/api/worker/applications?{$query}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['status'])
                ->assertJsonPath('errors.status.0', '選択した 状態は 無効です。');
        }

        $this->getJson('/api/worker/applications?status=')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$applied->id, $confirmed->id]);
    }

    public function test_キャンセルは理由区分の企業都合が出る(): void
    {
        // 募集の中止による自動キャンセルは企業都合（myteam-domain-rules）。ワーカー都合と区別して返す
        $byCompany = $this->application(['work_date' => '2026-10-05'], state: ApplicationStatus::Cancelled, reason: CancelReason::Company);
        $byWorker = $this->application(['work_date' => '2026-10-06'], state: ApplicationStatus::Cancelled, reason: CancelReason::Worker);
        $confirmed = $this->application(['work_date' => '2026-10-07'], state: ApplicationStatus::Confirmed);

        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/applications')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$byCompany->id, $byWorker->id, $confirmed->id])
            ->assertJsonPath('data.0.cancel_reason', ['value' => 'company', 'label' => '企業都合'])
            ->assertJsonPath('data.0.cancelled_at', '2026-10-01T10:00:00+09:00')
            ->assertJsonPath('data.1.cancel_reason', ['value' => 'worker', 'label' => 'ワーカー都合'])
            ->assertJsonPath('data.2.cancel_reason', null)
            ->assertJsonPath('data.2.cancelled_at', null);
    }

    public function test_応募の応答は契約の形で勤怠と報酬を含まない(): void
    {
        $confirmed = $this->application(state: ApplicationStatus::Confirmed);
        Attendance::factory()->create(['application_id' => $confirmed->id]);

        $response = $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/applications')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total'], 'links']);

        $this->assertSame([[
            'id' => $confirmed->id,
            'job_posting' => [
                'id' => $confirmed->job_posting_id,
                'job_title' => 'ピッキング',
                'work_date' => '2026-10-05',
                'start_time' => '09:00',
                'end_time' => '17:00',
                'location' => ['id' => $this->tokyo->id, 'name' => '東京倉庫'],
            ],
            'status' => ['value' => 'confirmed', 'label' => '確定'],
            'applied_at' => '2026-10-01T10:00:00+09:00',
            'confirmed_at' => '2026-10-01T10:00:00+09:00',
            'confirmed_hourly_wage' => 1300,
            'confirmed_transport_allowance' => 500,
            'cancel_reason' => null,
            'cancelled_at' => null,
        ]], $response->json('data'));
        // 勤怠と報酬（勤怠の行の金額 9,600 円）・ワーカーの氏名は出ない
        $body = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('山田太郎', $body);
        $this->assertStringNotContainsString('9600', $body);
    }

    public function test_応募一覧のクエリ数は件数に比例しない(): void
    {
        // 募集・拠点を応募ごとに別に作る（N+1 があれば件数に比例して増える）
        $make = fn (string $date) => $this->application([
            'work_date' => $date,
            'location_id' => Location::factory()->create()->id,
        ], state: ApplicationStatus::Confirmed);

        $make('2026-10-02');
        $this->actingAsWorker($this->worker);
        $this->getJson('/api/worker/applications')->assertOk();   // 認証などの初回分を済ませる

        $one = $this->countQueries(fn () => $this->getJson('/api/worker/applications')->assertOk()->assertJsonCount(1, 'data'));

        foreach (['2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07'] as $date) {
            $make($date);
        }

        $six = $this->countQueries(fn () => $this->getJson('/api/worker/applications')->assertOk()->assertJsonCount(6, 'data'));

        $this->assertSame($one, $six);
    }

    public function test_応募一覧のper_pageは1から100に丸め最後より後のページは空(): void
    {
        // 6.1 の共通のページング（Controller::perPage()）。ワーカーの一覧でも同じ丸めになること。
        // 数字でない値は 0 として 1 に丸める（WorkerJobPostingTest の per_page のテストと同じ）
        $this->application(['work_date' => '2026-10-02']);
        $this->application(['work_date' => '2026-10-03']);

        $this->actingAsWorker($this->worker);
        foreach (['0' => 1, '-5' => 1, '1' => 1, '100' => 100, '101' => 100, 'abc' => 1] as $input => $expected) {
            $this->getJson("/api/worker/applications?per_page={$input}")
                ->assertOk()
                ->assertJsonPath('meta.per_page', $expected)
                ->assertJsonPath('meta.total', 2);
        }

        // 2 ページ目は 2 件目（勤務日の順）だけ
        $this->getJson('/api/worker/applications?per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.*.job_posting.work_date', ['2026-10-03'])
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/worker/applications?per_page=1&page=3')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_同じ勤務日と開始時刻の応募は作った順ではなく応募の番号の昇順(): void
    {
        // 作る順と ID の順を逆にする（ID を明示）。作成日時やページの境目で並びが揺れないこと
        $large = Application::factory()->create([
            'id' => 900,
            'job_posting_id' => $this->posting()->id,
            'worker_id' => $this->worker->id,
        ]);
        $small = Application::factory()->create([
            'id' => 100,
            'job_posting_id' => $this->posting()->id,
            'worker_id' => $this->worker->id,
        ]);

        $this->actingAsWorker($this->worker);
        $this->getJson('/api/worker/applications')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$small->id, $large->id]);

        // ページを分けても重ならず漏れない
        $this->getJson('/api/worker/applications?per_page=1&page=1')->assertJsonPath('data.*.id', [$small->id]);
        $this->getJson('/api/worker/applications?per_page=1&page=2')->assertJsonPath('data.*.id', [$large->id]);
    }

    public function test_深夜跨ぎの勤務は勤務日と開始時刻で並び終了時刻はそのまま返る(): void
    {
        // 10/05 22:00〜翌 05:00 の勤務は 10/05 の勤務。終了時刻（翌日の 05:00）ではなく勤務日と開始時刻で並ぶ
        $overnight = $this->application(['work_date' => '2026-10-05', 'start_time' => '22:00', 'end_time' => '05:00']);
        $nextMorning = $this->application(['work_date' => '2026-10-06', 'start_time' => '04:00', 'end_time' => '08:00']);
        $sameDayMorning = $this->application(['work_date' => '2026-10-05', 'start_time' => '06:00', 'end_time' => '10:00']);

        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/applications')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$sameDayMorning->id, $overnight->id, $nextMorning->id])
            ->assertJsonPath('data.1.job_posting.work_date', '2026-10-05')
            ->assertJsonPath('data.1.job_posting.start_time', '22:00')
            ->assertJsonPath('data.1.job_posting.end_time', '05:00');
    }

    public function test_状態の絞り込みは前後の空白を除き空白だけなら絞り込まない(): void
    {
        $applied = $this->application(['work_date' => '2026-10-05']);
        $confirmed = $this->application(['work_date' => '2026-10-06'], state: ApplicationStatus::Confirmed);

        $this->actingAsWorker($this->worker);
        // 前後の空白は TrimStrings で除かれる（ほかの入力と同じ）。空白だけは空と同じで絞り込まない
        $this->getJson('/api/worker/applications?status=%20confirmed%20')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$confirmed->id]);
        $this->getJson('/api/worker/applications?status=%20%20')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$applied->id, $confirmed->id]);
    }

    public function test_該当する応募がなければ空の一覧(): void
    {
        $this->application();

        $this->actingAsWorker($this->worker);
        $this->getJson('/api/worker/applications?status=rejected')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);

        // 応募が 1 件もないワーカー
        $this->actingAsWorker(Worker::factory()->registered()->create())
            ->getJson('/api/worker/applications')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_締切や中止の募集への応募も一覧に出て募集の状態は返さない(): void
    {
        // 募集の中止で企業都合のキャンセルになった応募（要件 4.8 の動線 6）と、締め切られた募集への確定の応募。
        // ワーカーの一覧は「募集中かつ今日以降」の募集の絞り込み（scopeListedForWorkers）を使わない
        $closed = $this->application(['work_date' => '2026-10-05', 'status' => JobPostingStatus::Closed], state: ApplicationStatus::Confirmed);
        $cancelled = $this->application(
            ['work_date' => '2026-10-06', 'status' => JobPostingStatus::Cancelled],
            state: ApplicationStatus::Cancelled,
            reason: CancelReason::Company,
        );

        $response = $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/applications')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$closed->id, $cancelled->id])
            ->assertJsonPath('data.0.status', ['value' => 'confirmed', 'label' => '確定'])
            ->assertJsonPath('data.1.status', ['value' => 'cancelled', 'label' => 'キャンセル'])
            ->assertJsonPath('data.1.cancel_reason', ['value' => 'company', 'label' => '企業都合']);

        $this->assertArrayNotHasKey('status', $response->json('data.0.job_posting'));
    }

    public function test_確定の後にキャンセルされた応募は確定時の値を残し却下は確定の値が空(): void
    {
        // 確定時にコピーした時給・交通費は、キャンセルの後も残る（報酬は確定時の値だけを見る。myteam-domain-rules）
        $cancelled = $this->application(['work_date' => '2026-10-05'], state: ApplicationStatus::Cancelled);
        $rejected = $this->application(['work_date' => '2026-10-06'], state: ApplicationStatus::Rejected);
        // 確定の後に募集の時給を上げても、応募の確定時の値は変わらない
        $confirmed = $this->application(['work_date' => '2026-10-07'], state: ApplicationStatus::Confirmed);
        $confirmed->jobPosting->update(['hourly_wage' => 2000, 'transport_allowance' => 800]);

        $this->actingAsWorker($this->worker)
            ->getJson('/api/worker/applications')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$cancelled->id, $rejected->id, $confirmed->id])
            ->assertJsonPath('data.0.confirmed_at', '2026-09-30T10:00:00+09:00')
            ->assertJsonPath('data.0.confirmed_hourly_wage', 1300)
            ->assertJsonPath('data.0.confirmed_transport_allowance', 500)
            ->assertJsonPath('data.1.status', ['value' => 'rejected', 'label' => '却下'])
            ->assertJsonPath('data.1.confirmed_at', null)
            ->assertJsonPath('data.1.confirmed_hourly_wage', null)
            ->assertJsonPath('data.1.confirmed_transport_allowance', null)
            ->assertJsonPath('data.1.cancel_reason', null)
            ->assertJsonPath('data.2.confirmed_hourly_wage', 1300)
            ->assertJsonPath('data.2.confirmed_transport_allowance', 500);
    }

    /**
     * 自分（$this->worker）の応募を、明示した募集に作る。$state をファクトリの状態に振り分ける（$reason はキャンセルのときだけ使う）
     *
     * @param  array<string, mixed>  $postingAttributes
     */
    private function application(
        array $postingAttributes = [],
        ApplicationStatus $state = ApplicationStatus::Applied,
        CancelReason $reason = CancelReason::Worker,
    ): Application {
        $factory = match ($state) {
            ApplicationStatus::Applied => Application::factory(),
            ApplicationStatus::Confirmed => Application::factory()->confirmed(),
            ApplicationStatus::Rejected => Application::factory()->rejected(),
            ApplicationStatus::Cancelled => Application::factory()->cancelled($reason),
        };

        return $factory->create([
            'job_posting_id' => $this->posting($postingAttributes)->id,
            'worker_id' => $this->worker->id,
        ]);
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

    /**
     * 応募が 422（文言どおり・errors は空）で拒否され、応募の行が増えないこと
     */
    private function assertRejected(JobPosting $posting, string $message, int $expectedCount = 0): void
    {
        $before = Application::query()->count();

        $this->actingAsWorker($this->worker)
            ->postJson("/api/worker/job-postings/{$posting->id}/applications")
            ->assertUnprocessable()
            ->assertExactJson(['message' => $message, 'errors' => []]);

        $this->assertSame($before, Application::query()->count());
        $this->assertSame($expectedCount, Application::query()->where('worker_id', $this->worker->id)->count());
    }

    /**
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
}
