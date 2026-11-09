<?php

namespace Tests\Feature;

use App\Enums\JobPostingStatus;
use App\Http\Middleware\AuthenticateWorker;
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

    // ---- 判定の順の境界（T12 で決定。docs/01 への追記は T15: 無効 → 締切・中止 → 勤務日 → 二重 → 残り枠） ----

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
