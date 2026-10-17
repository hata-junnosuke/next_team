<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Events\JobPostingCancelled;
use App\Models\Application;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Services\JobPostingService;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * F-05〜F-08 募集の作成・編集・一覧・締切・中止・複製（docs/01 の募集の API、サービス契約、業務ルールの決定）。
 */
class JobPostingTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $location = $overrides['location_id'] ?? Location::factory()->create()->id;

        return [
            'location_id' => $location,
            'job_title' => 'ピッキング',
            'work_date' => '2026-10-20',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 3,
            'hourly_wage' => 1300,
            'transport_allowance' => 500,
            ...$overrides,
        ];
    }

    public function test_募集を作成できる(): void
    {
        $location = Location::factory()->create(['name' => '渋谷店']);

        $response = $this->actingAsAdmin()
            ->postJson('/api/job-postings', $this->payload(['location_id' => $location->id]))
            ->assertCreated();

        $id = JobPosting::query()->value('id');
        $response->assertExactJson(['data' => [
            'id' => $id,
            'location' => ['id' => $location->id, 'name' => '渋谷店'],
            'job_title' => 'ピッキング',
            'work_date' => '2026-10-20',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 3,
            'hourly_wage' => 1300,
            'transport_allowance' => 500,
            'status' => ['value' => 'open', 'label' => '募集中'],
            'applications_count' => 0,
            'confirmed_count' => 0,
            'can' => ['edit' => true, 'close' => true, 'cancel' => true, 'apply' => true],
        ]]);

        $this->assertDatabaseHas('job_postings', [
            'id' => $id,
            'company_id' => $this->company->id,
            'work_date' => '2026-10-20',
            'status' => 'open',
        ]);
    }

    public function test_交通費を省略すると0で作成される(): void
    {
        $payload = $this->payload();
        unset($payload['transport_allowance']);

        $this->actingAsAdmin()
            ->postJson('/api/job-postings', $payload)
            ->assertCreated()
            ->assertJsonPath('data.transport_allowance', 0);
    }

    public function test_必須項目が空だと422で日本語の項目名が出る(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/job-postings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'location_id' => '拠点は必須項目です。',
                'job_title' => '職種名は必須項目です。',
                'work_date' => '勤務日は必須項目です。',
                'start_time' => '開始時刻は必須項目です。',
                'end_time' => '終了時刻は必須項目です。',
                'capacity' => '募集人数は必須項目です。',
                'hourly_wage' => '時給は必須項目です。',
            ]);

        $this->assertDatabaseCount('job_postings', 0);
    }

    public function test_終了が開始より前だと422(): void
    {
        $this->actingAsAdmin();

        foreach (['08:00', '09:00'] as $end) {
            $this->postJson('/api/job-postings', $this->payload(['start_time' => '09:00', 'end_time' => $end]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['end_time' => '終了時刻は開始時刻より後にしてください。']);
        }

        $this->assertDatabaseCount('job_postings', 0);
    }

    public function test_募集人数が0だと422(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/job-postings', $this->payload(['capacity' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capacity' => '募集人数には、1以上の数値を指定してください。']);
    }

    public function test_最低賃金未満だと422(): void
    {
        config(['myteam.min_wage' => 1226]);
        $this->actingAsAdmin();

        $this->postJson('/api/job-postings', $this->payload(['hourly_wage' => 1225]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hourly_wage' => '時給は最低賃金（1226円）以上にしてください。']);

        $this->postJson('/api/job-postings', $this->payload(['hourly_wage' => 1226]))->assertCreated();
    }

    public function test_時刻と日付の形式が違うと422(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/job-postings', $this->payload(['work_date' => '2026/10/20', 'start_time' => '9時', 'end_time' => '1700']))
            ->assertUnprocessable()
            // 既定の文言（「勤務日の形式が'Y-m-d'と一致しません。」）は書式記号が見えるので、日本語の案内に差し替えている
            ->assertJsonValidationErrors([
                'work_date' => '勤務日は YYYY-MM-DD の形式で入力してください。',
                'start_time' => '開始時刻は HH:MM の形式で入力してください。',
                'end_time' => '終了時刻は HH:MM の形式で入力してください。',
            ]);
    }

    public function test_他社の拠点は指定できず422(): void
    {
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Location::factory()->create());
        Context::forget('company_id');

        $this->actingAsAdmin()
            ->postJson('/api/job-postings', $this->payload(['location_id' => $theirs->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location_id' => '選択された拠点は、有効ではありません。']);

        $this->assertDatabaseCount('job_postings', 0);
    }

    public function test_募集の詳細を取得できる(): void
    {
        $posting = JobPosting::factory()->create();
        Application::factory()->for($posting)->create();
        Application::factory()->for($posting)->confirmed()->create();

        $this->actingAsAdmin()
            ->getJson("/api/job-postings/{$posting->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $posting->id)
            ->assertJsonPath('data.location.id', $posting->location_id)
            ->assertJsonPath('data.applications_count', 2)
            ->assertJsonPath('data.confirmed_count', 1);
    }

    public function test_募集を編集できる(): void
    {
        $posting = JobPosting::factory()->create();
        $newLocation = Location::factory()->create(['name' => '新宿店']);

        $this->actingAsAdmin()
            ->putJson("/api/job-postings/{$posting->id}", $this->payload([
                'location_id' => $newLocation->id,
                'job_title' => '検品',
                'work_date' => '2026-11-01',
                'start_time' => '10:00',
                'end_time' => '15:30',
                'capacity' => 5,
                'hourly_wage' => 1400,
                'transport_allowance' => 0,
            ]))
            ->assertOk()
            ->assertJsonPath('data.location', ['id' => $newLocation->id, 'name' => '新宿店'])
            ->assertJsonPath('data.job_title', '検品')
            ->assertJsonPath('data.work_date', '2026-11-01')
            ->assertJsonPath('data.start_time', '10:00')
            ->assertJsonPath('data.end_time', '15:30')
            ->assertJsonPath('data.capacity', 5);

        $this->assertDatabaseHas('job_postings', ['id' => $posting->id, 'job_title' => '検品', 'work_date' => '2026-11-01']);
    }

    public function test_編集でも最低賃金未満だと422(): void
    {
        $posting = JobPosting::factory()->create(['hourly_wage' => 1300]);

        $this->actingAsAdmin()
            ->putJson("/api/job-postings/{$posting->id}", $this->payload(['hourly_wage' => 1000]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hourly_wage']);

        $this->assertDatabaseHas('job_postings', ['id' => $posting->id, 'hourly_wage' => 1300]);
    }

    public function test_日付と拠点と状態で絞り込める(): void
    {
        $shibuya = Location::factory()->create();
        $shinjuku = Location::factory()->create();
        $hit1 = JobPosting::factory()->for($shibuya)->onDate('2026-10-10')->create();
        $hit2 = JobPosting::factory()->for($shibuya)->onDate('2026-10-31')->create();
        JobPosting::factory()->for($shibuya)->onDate('2026-10-09')->create();   // 期間の前
        JobPosting::factory()->for($shibuya)->onDate('2026-11-01')->create();   // 期間の後
        JobPosting::factory()->for($shinjuku)->onDate('2026-10-15')->create();  // 別の拠点
        JobPosting::factory()->for($shibuya)->onDate('2026-10-15')->closed()->create(); // 別の状態

        $response = $this->actingAsAdmin()
            ->getJson("/api/job-postings?date_from=2026-10-10&date_to=2026-10-31&location_id={$shibuya->id}&status=open")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->assertSame([$hit1->id, $hit2->id], array_column($response->json('data'), 'id'));

        $this->getJson('/api/job-postings?status=closed')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/job-postings')->assertOk()->assertJsonPath('meta.total', 6);
    }

    public function test_一覧は勤務日の昇順で件数が入る(): void
    {
        $late = JobPosting::factory()->onDate('2026-10-30')->create();
        $early = JobPosting::factory()->onDate('2026-10-01')->create();
        Application::factory()->count(2)->for($early)->create();
        Application::factory()->for($early)->confirmed()->create();

        $response = $this->actingAsAdmin()
            ->getJson('/api/job-postings')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'location' => ['id', 'name'], 'job_title', 'work_date', 'start_time', 'end_time',
                    'capacity', 'hourly_wage', 'transport_allowance', 'status' => ['value', 'label'],
                    'applications_count', 'confirmed_count', 'can' => ['edit', 'close', 'cancel', 'apply'],
                ]],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links',
            ])
            ->assertJsonPath('data.0.applications_count', 3)
            ->assertJsonPath('data.0.confirmed_count', 1);

        $this->assertSame([$early->id, $late->id], array_column($response->json('data'), 'id'));
    }

    public function test_一覧のクエリ数は件数に比例しない(): void
    {
        JobPosting::factory()->count(5)->create();
        $this->actingAsAdmin()->getJson('/api/job-postings')->assertOk();   // 認証などの初回分を済ませる

        DB::enableQueryLog();
        $this->getJson('/api/job-postings')->assertOk()->assertJsonCount(5, 'data');
        $few = count(DB::getQueryLog());

        JobPosting::factory()->count(5)->create();
        DB::flushQueryLog();
        $this->getJson('/api/job-postings')->assertOk()->assertJsonCount(10, 'data');
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($few, $many);
    }

    public function test_絞り込みの値が不正だと422(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/job-postings?date_from=abc&date_to=2026/10/31&status=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'date_from' => '勤務日（から）は YYYY-MM-DD の形式で指定してください。',
                'date_to' => '勤務日（まで）は YYYY-MM-DD の形式で指定してください。',
                'status',
            ]);
    }

    public function test_締め切ると状態がclosedになる(): void
    {
        $posting = JobPosting::factory()->create();

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', ['value' => 'closed', 'label' => '締切'])
            ->assertJsonPath('data.can', ['edit' => true, 'close' => false, 'cancel' => true, 'apply' => false]);

        $this->assertSame(JobPostingStatus::Closed, $posting->fresh()->status);

        // 締切をもう一度は遷移できない
        $this->postJson("/api/job-postings/{$posting->id}/close")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'この募集はすでに締切です。');
    }

    public function test_中止すると確定済みは企業都合キャンセルになり応募中は却下になる(): void
    {
        $this->freezeSecond();
        $posting = JobPosting::factory()->create();
        $confirmed = Application::factory()->for($posting)->confirmed()->create();
        $applied = Application::factory()->for($posting)->create();
        $rejected = Application::factory()->for($posting)->rejected()->create();
        $cancelledByWorker = Application::factory()->for($posting)->cancelled(CancelReason::Worker)->create();
        $otherPosting = Application::factory()->confirmed()->create();

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', ['value' => 'cancelled', 'label' => '中止'])
            ->assertJsonPath('data.confirmed_count', 0)
            ->assertJsonPath('data.can', ['edit' => false, 'close' => false, 'cancel' => false, 'apply' => false]);

        $confirmed->refresh();
        $this->assertSame(ApplicationStatus::Cancelled, $confirmed->status);
        $this->assertSame(CancelReason::Company, $confirmed->cancel_reason);
        $this->assertTrue($confirmed->cancelled_at->equalTo(now()));

        $this->assertSame(ApplicationStatus::Rejected, $applied->fresh()->status);
        $this->assertNull($applied->fresh()->cancel_reason);
        $this->assertSame(ApplicationStatus::Rejected, $rejected->fresh()->status);
        $this->assertSame(CancelReason::Worker, $cancelledByWorker->fresh()->cancel_reason);
        $this->assertSame(ApplicationStatus::Confirmed, $otherPosting->fresh()->status);
        $this->assertSame(JobPostingStatus::Cancelled, $posting->fresh()->status);
    }

    public function test_締切後の募集も中止できる(): void
    {
        $posting = JobPosting::factory()->closed()->create();

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'cancelled');
    }

    public function test_中止した募集はもう一度中止できず422(): void
    {
        $posting = JobPosting::factory()->cancelled()->create();

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'この募集はすでに中止されています。');
        $this->postJson("/api/job-postings/{$posting->id}/close")->assertUnprocessable();
    }

    public function test_中止すると_job_posting_cancelledがコミット後に発火する(): void
    {
        $this->assertContains(ShouldDispatchAfterCommit::class, class_implements(JobPostingCancelled::class));

        Event::fake([JobPostingCancelled::class]);
        $posting = JobPosting::factory()->create();
        $confirmed = Application::factory()->for($posting)->count(2)->confirmed()->create();
        Application::factory()->for($posting)->create();   // 応募中（却下になる）は対象外

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        Event::assertDispatchedTimes(JobPostingCancelled::class, 1);
        Event::assertDispatched(JobPostingCancelled::class, function (JobPostingCancelled $event) use ($posting, $confirmed) {
            return $event->jobPostingId === $posting->id
                && $event->cancelledApplicationIds === $confirmed->pluck('id')->sort()->values()->all();
        });
    }

    public function test_中止がロールバックされると_job_posting_cancelledは発火しない(): void
    {
        Event::fake([JobPostingCancelled::class]);
        $posting = JobPosting::factory()->create();
        Application::factory()->for($posting)->confirmed()->create();

        // 呼び出し側の外側のトランザクションがロールバックされた場合（例外で落ちても後続のテストに開いたままのトランザクションを残さない）
        DB::beginTransaction();
        try {
            app(JobPostingService::class)->cancel($posting);
        } finally {
            DB::rollBack();
        }

        Event::assertNotDispatched(JobPostingCancelled::class);
        $this->assertSame(JobPostingStatus::Open, $posting->fresh()->status);
    }

    public function test_中止した募集は編集できず422(): void
    {
        $posting = JobPosting::factory()->cancelled()->create(['job_title' => '元の職種']);

        $this->actingAsAdmin()
            ->putJson("/api/job-postings/{$posting->id}", $this->payload(['job_title' => '変更']))
            ->assertUnprocessable()
            ->assertJsonPath('message', '中止した募集は編集できません。');

        $this->assertDatabaseHas('job_postings', ['id' => $posting->id, 'job_title' => '元の職種']);
    }

    public function test_締切後の募集は編集できる(): void
    {
        $posting = JobPosting::factory()->closed()->create();

        $this->actingAsAdmin()
            ->putJson("/api/job-postings/{$posting->id}", $this->payload(['job_title' => '変更']))
            ->assertOk()
            ->assertJsonPath('data.job_title', '変更')
            ->assertJsonPath('data.status.value', 'closed');
    }

    public function test_募集人数を確定数より少なくできない(): void
    {
        $posting = JobPosting::factory()->create(['capacity' => 3]);
        Application::factory()->for($posting)->count(2)->confirmed()->create();
        Application::factory()->for($posting)->count(2)->create();   // 応募中は数えない

        $this->actingAsAdmin()
            ->putJson("/api/job-postings/{$posting->id}", $this->payload(['location_id' => $posting->location_id, 'capacity' => 1]))
            ->assertUnprocessable()
            ->assertJsonPath('message', '募集人数は確定数（2名）より少なくできません。')
            ->assertJsonPath('errors.capacity.0', '募集人数は確定数（2名）より少なくできません。');

        $this->assertDatabaseHas('job_postings', ['id' => $posting->id, 'capacity' => 3]);

        // 確定数ちょうどまでは減らせる
        $this->putJson("/api/job-postings/{$posting->id}", $this->payload(['location_id' => $posting->location_id, 'capacity' => 2]))
            ->assertOk()
            ->assertJsonPath('data.capacity', 2);
    }

    public function test_別日に複製できる(): void
    {
        $posting = JobPosting::factory()->closed()->onDate('2026-10-10')->create([
            'job_title' => '品出し',
            'start_time' => '22:00',
            'end_time' => '23:30',
            'capacity' => 4,
            'hourly_wage' => 1500,
            'transport_allowance' => 300,
        ]);
        Application::factory()->for($posting)->confirmed()->create();

        $response = $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/duplicate", ['work_date' => '2026-10-17'])
            ->assertCreated()
            ->assertJsonPath('data.location.id', $posting->location_id)
            ->assertJsonPath('data.job_title', '品出し')
            ->assertJsonPath('data.work_date', '2026-10-17')
            ->assertJsonPath('data.start_time', '22:00')
            ->assertJsonPath('data.end_time', '23:30')
            ->assertJsonPath('data.capacity', 4)
            ->assertJsonPath('data.hourly_wage', 1500)
            ->assertJsonPath('data.transport_allowance', 300)
            ->assertJsonPath('data.status.value', 'open')
            ->assertJsonPath('data.applications_count', 0)
            ->assertJsonPath('data.confirmed_count', 0);

        $newId = $response->json('data.id');
        $this->assertNotSame($posting->id, $newId);
        $this->assertDatabaseHas('job_postings', ['id' => $newId, 'work_date' => '2026-10-17', 'company_id' => $this->company->id]);
        $this->assertSame(0, Application::query()->where('job_posting_id', $newId)->count());
        // 元の募集は変わらない
        $this->assertSame(JobPostingStatus::Closed, $posting->fresh()->status);
    }

    public function test_複製は勤務日が必須(): void
    {
        $posting = JobPosting::factory()->create();

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/duplicate", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_date' => '勤務日は必須項目です。']);

        $this->assertDatabaseCount('job_postings', 1);
    }

    public function test_他社の募集は404(): void
    {
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => JobPosting::factory()->create());
        Context::forget('company_id');

        $this->actingAsAdmin()
            ->getJson("/api/job-postings/{$theirs->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);
        $this->putJson("/api/job-postings/{$theirs->id}", ['job_title' => '乗っ取り'])->assertNotFound();
        $this->postJson("/api/job-postings/{$theirs->id}/close")->assertNotFound();
        $this->postJson("/api/job-postings/{$theirs->id}/cancel")->assertNotFound();
        $this->postJson("/api/job-postings/{$theirs->id}/duplicate", ['work_date' => '2026-10-17'])->assertNotFound();

        $this->getJson('/api/job-postings')->assertOk()->assertJsonPath('meta.total', 0);

        CurrentCompany::set($other->id);
        $this->assertSame(JobPostingStatus::Open, $theirs->fresh()->status);
        $this->assertSame(1, JobPosting::query()->count());
    }

    public function test_未ログインだと401(): void
    {
        Context::forget('company_id');

        $this->getJson('/api/job-postings')->assertUnauthorized();
        $this->postJson('/api/job-postings', [])->assertUnauthorized();
    }

    // ---- 以下、tester による補強（受け入れ条件の境界と異常系） ----

    public function test_未ログインだと詳細編集締切中止複製も401(): void
    {
        $posting = JobPosting::factory()->create();
        Context::forget('company_id');

        $this->getJson("/api/job-postings/{$posting->id}")->assertUnauthorized();
        $this->putJson("/api/job-postings/{$posting->id}", [])->assertUnauthorized();
        $this->postJson("/api/job-postings/{$posting->id}/close")->assertUnauthorized();
        $this->postJson("/api/job-postings/{$posting->id}/cancel")->assertUnauthorized();
        $this->postJson("/api/job-postings/{$posting->id}/duplicate", ['work_date' => '2026-10-17'])->assertUnauthorized();

        CurrentCompany::set($this->company->id);
        $this->assertSame(JobPostingStatus::Open, $posting->fresh()->status);
        $this->assertSame(1, JobPosting::query()->count());
    }

    public function test_既定の最低賃金1226円の境界で1225は422で1226は作成できる(): void
    {
        // config を上書きせず、phpunit.xml の MYTEAM_MIN_WAGE（= config/myteam.php の既定値）で判定されること
        $this->assertSame(1226, config('myteam.min_wage'));
        $this->actingAsAdmin();

        $this->postJson('/api/job-postings', $this->payload(['hourly_wage' => 1225]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hourly_wage' => '時給は最低賃金（1226円）以上にしてください。']);
        $this->assertDatabaseCount('job_postings', 0);

        $this->postJson('/api/job-postings', $this->payload(['hourly_wage' => 1226]))
            ->assertCreated()
            ->assertJsonPath('data.hourly_wage', 1226);
    }

    public function test_編集でも最低賃金の境界で1225は422で1226は通る(): void
    {
        $posting = JobPosting::factory()->create(['hourly_wage' => 1300]);
        $this->actingAsAdmin();

        $this->putJson("/api/job-postings/{$posting->id}", $this->payload(['location_id' => $posting->location_id, 'hourly_wage' => 1225]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hourly_wage' => '時給は最低賃金（1226円）以上にしてください。']);
        $this->assertDatabaseHas('job_postings', ['id' => $posting->id, 'hourly_wage' => 1300]);

        $this->putJson("/api/job-postings/{$posting->id}", $this->payload(['location_id' => $posting->location_id, 'hourly_wage' => 1226]))
            ->assertOk()
            ->assertJsonPath('data.hourly_wage', 1226);
    }

    public function test_最低賃金の設定を変えると判定も変わる(): void
    {
        config(['myteam.min_wage' => 1300]);

        $this->actingAsAdmin()
            ->postJson('/api/job-postings', $this->payload(['hourly_wage' => 1299]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hourly_wage' => '時給は最低賃金（1300円）以上にしてください。']);
    }

    public function test_募集人数は1なら作成できる(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/job-postings', $this->payload(['capacity' => 1]))
            ->assertCreated()
            ->assertJsonPath('data.capacity', 1);
    }

    public function test_交通費が負だと422で空欄は0になる(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/job-postings', $this->payload(['transport_allowance' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transport_allowance']);

        $this->postJson('/api/job-postings', $this->payload(['transport_allowance' => '']))
            ->assertCreated()
            ->assertJsonPath('data.transport_allowance', 0);
    }

    public function test_作成時にstatusとcompany_idを送っても無視される(): void
    {
        $other = Company::factory()->create();

        $response = $this->actingAsAdmin()
            ->postJson('/api/job-postings', $this->payload(['status' => 'cancelled', 'company_id' => $other->id]))
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'open');

        $this->assertDatabaseHas('job_postings', [
            'id' => $response->json('data.id'),
            'company_id' => $this->company->id,
            'status' => 'open',
        ]);
    }

    public function test_編集でstatusを送っても状態は変わらない(): void
    {
        $posting = JobPosting::factory()->create();

        $this->actingAsAdmin()
            ->putJson("/api/job-postings/{$posting->id}", $this->payload(['location_id' => $posting->location_id, 'status' => 'cancelled']))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'open');

        $this->assertSame(JobPostingStatus::Open, $posting->fresh()->status);
    }

    public function test_編集で他社の拠点は指定できず422(): void
    {
        $posting = JobPosting::factory()->create();
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Location::factory()->create());
        Context::forget('company_id');

        $this->actingAsAdmin()
            ->putJson("/api/job-postings/{$posting->id}", $this->payload(['location_id' => $theirs->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location_id' => '選択された拠点は、有効ではありません。']);

        $this->assertDatabaseHas('job_postings', ['id' => $posting->id, 'location_id' => $posting->location_id]);
    }

    public function test_編集でも終了が開始以前だと422(): void
    {
        $posting = JobPosting::factory()->create();

        $this->actingAsAdmin()
            ->putJson("/api/job-postings/{$posting->id}", $this->payload([
                'location_id' => $posting->location_id, 'start_time' => '10:00', 'end_time' => '10:00',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_time' => '終了時刻は開始時刻より後にしてください。']);
    }

    public function test_募集人数は確定数ちょうどまで減らせて応募中却下キャンセルは数えない(): void
    {
        $posting = JobPosting::factory()->create(['capacity' => 5]);
        Application::factory()->for($posting)->confirmed()->create();
        Application::factory()->for($posting)->count(2)->create();
        Application::factory()->for($posting)->rejected()->create();
        Application::factory()->for($posting)->cancelled(CancelReason::Worker)->create();
        $this->actingAsAdmin();

        // 確定 1 件。確定数 - 1 = 0 はそもそも capacity >= 1 の検証で落ちる
        $this->putJson("/api/job-postings/{$posting->id}", $this->payload(['location_id' => $posting->location_id, 'capacity' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capacity']);

        $this->putJson("/api/job-postings/{$posting->id}", $this->payload(['location_id' => $posting->location_id, 'capacity' => 1]))
            ->assertOk()
            ->assertJsonPath('data.capacity', 1)
            ->assertJsonPath('data.confirmed_count', 1)
            ->assertJsonPath('data.applications_count', 5);
    }

    public function test_募集人数を確定数より1少なくすると422(): void
    {
        $posting = JobPosting::factory()->create(['capacity' => 3]);
        Application::factory()->for($posting)->count(3)->confirmed()->create();

        $this->actingAsAdmin()
            ->putJson("/api/job-postings/{$posting->id}", $this->payload(['location_id' => $posting->location_id, 'capacity' => 2]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.capacity.0', '募集人数は確定数（3名）より少なくできません。');

        $this->putJson("/api/job-postings/{$posting->id}", $this->payload(['location_id' => $posting->location_id, 'capacity' => 3]))
            ->assertOk();
    }

    public function test_締め切っても応募は変わらない(): void
    {
        $posting = JobPosting::factory()->create();
        $applied = Application::factory()->for($posting)->create();
        $confirmed = Application::factory()->for($posting)->confirmed()->create();

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/close")
            ->assertOk()
            ->assertJsonPath('data.applications_count', 2)
            ->assertJsonPath('data.confirmed_count', 1);

        $this->assertSame(ApplicationStatus::Applied, $applied->fresh()->status);
        $this->assertSame(ApplicationStatus::Confirmed, $confirmed->fresh()->status);
    }

    public function test_中止した募集は締め切れず422(): void
    {
        $posting = JobPosting::factory()->cancelled()->create();

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/close")
            ->assertUnprocessable()
            ->assertJsonPath('message', '中止した募集は締め切れません。')
            ->assertJsonPath('errors', []);

        $this->assertSame(JobPostingStatus::Cancelled, $posting->fresh()->status);
    }

    public function test_締切後の募集を中止すると確定は企業都合キャンセルに応募中は却下になる(): void
    {
        $this->freezeSecond();
        $posting = JobPosting::factory()->closed()->create();
        $confirmed = Application::factory()->for($posting)->confirmed()->create();
        $applied = Application::factory()->for($posting)->create();

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        $confirmed->refresh();
        $this->assertSame(ApplicationStatus::Cancelled, $confirmed->status);
        $this->assertSame(CancelReason::Company, $confirmed->cancel_reason);
        $this->assertTrue($confirmed->cancelled_at->equalTo(now()));
        $this->assertSame(ApplicationStatus::Rejected, $applied->fresh()->status);
    }

    public function test_中止しても既にキャンセル済みの応募の理由と日時は上書きされない(): void
    {
        $this->freezeSecond();
        $posting = JobPosting::factory()->create();
        $workerCancelled = Application::factory()->for($posting)->cancelled(CancelReason::Worker)->create(['cancelled_at' => now()->subDays(3)]);
        $companyCancelled = Application::factory()->for($posting)->cancelled(CancelReason::Company)->create(['cancelled_at' => now()->subDays(2)]);
        $rejected = Application::factory()->for($posting)->rejected()->create();

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        $workerCancelled->refresh();
        $this->assertSame(ApplicationStatus::Cancelled, $workerCancelled->status);
        $this->assertSame(CancelReason::Worker, $workerCancelled->cancel_reason);
        $this->assertTrue($workerCancelled->cancelled_at->equalTo(now()->subDays(3)));

        $companyCancelled->refresh();
        $this->assertTrue($companyCancelled->cancelled_at->equalTo(now()->subDays(2)));

        $rejected->refresh();
        $this->assertSame(ApplicationStatus::Rejected, $rejected->status);
        $this->assertNull($rejected->cancel_reason);
        $this->assertNull($rejected->cancelled_at);
    }

    public function test_中止で却下になった応募中には理由と日時が入らない(): void
    {
        $posting = JobPosting::factory()->create();
        $applied = Application::factory()->for($posting)->create();

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        $applied->refresh();
        $this->assertSame(ApplicationStatus::Rejected, $applied->status);
        $this->assertNull($applied->cancel_reason);
        $this->assertNull($applied->cancelled_at);
    }

    public function test_中止のイベントには今回キャンセルになった応募だけが入る(): void
    {
        Event::fake([JobPostingCancelled::class]);
        $posting = JobPosting::factory()->create();
        $confirmed = Application::factory()->for($posting)->confirmed()->create();
        Application::factory()->for($posting)->cancelled(CancelReason::Worker)->create();   // 既にキャンセル済み
        Application::factory()->for($posting)->rejected()->create();
        Application::factory()->for($posting)->create();

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        Event::assertDispatched(
            JobPostingCancelled::class,
            fn (JobPostingCancelled $event) => $event->jobPostingId === $posting->id
                && $event->cancelledApplicationIds === [$confirmed->id],
        );
    }

    public function test_確定がない募集を中止するとイベントは空の配列で発火する(): void
    {
        Event::fake([JobPostingCancelled::class]);
        $posting = JobPosting::factory()->create();
        Application::factory()->for($posting)->create();

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        Event::assertDispatchedTimes(JobPostingCancelled::class, 1);
        Event::assertDispatched(JobPostingCancelled::class, fn (JobPostingCancelled $event) => $event->cancelledApplicationIds === []);
    }

    public function test_中止の再実行は422で応募もイベントも変わらない(): void
    {
        $posting = JobPosting::factory()->create();
        $confirmed = Application::factory()->for($posting)->confirmed()->create();
        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();
        $cancelledAt = $confirmed->fresh()->cancelled_at;

        Event::fake([JobPostingCancelled::class]);
        $this->travel(1)->hours();
        $this->postJson("/api/job-postings/{$posting->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'この募集はすでに中止されています。');

        Event::assertNotDispatched(JobPostingCancelled::class);
        $this->assertTrue($confirmed->fresh()->cancelled_at->equalTo($cancelledAt));
    }

    public function test_canは状態ごとに決まる(): void
    {
        $open = JobPosting::factory()->create();
        $closed = JobPosting::factory()->closed()->create();
        $cancelled = JobPosting::factory()->cancelled()->create();
        $this->actingAsAdmin();

        $this->getJson("/api/job-postings/{$open->id}")
            ->assertJsonPath('data.can', ['edit' => true, 'close' => true, 'cancel' => true, 'apply' => true]);
        $this->getJson("/api/job-postings/{$closed->id}")
            ->assertJsonPath('data.can', ['edit' => true, 'close' => false, 'cancel' => true, 'apply' => false]);
        $this->getJson("/api/job-postings/{$cancelled->id}")
            ->assertJsonPath('data.can', ['edit' => false, 'close' => false, 'cancel' => false, 'apply' => false]);
    }

    public function test_詳細の形はdocs01の6_4どおり(): void
    {
        $posting = JobPosting::factory()->create();

        $this->actingAsAdmin()
            ->getJson("/api/job-postings/{$posting->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'location' => ['id', 'name'], 'job_title', 'work_date', 'start_time', 'end_time',
                'capacity', 'hourly_wage', 'transport_allowance', 'status' => ['value', 'label'],
                'applications_count', 'confirmed_count', 'can' => ['edit', 'close', 'cancel', 'apply'],
            ]])
            ->assertJsonCount(13, 'data')
            ->assertJsonPath('data.start_time', '09:00')
            ->assertJsonPath('data.end_time', '17:00');
    }

    public function test_中止した募集も複製でき新しい募集は募集中で全操作可能(): void
    {
        $posting = JobPosting::factory()->cancelled()->create();
        Application::factory()->for($posting)->cancelled(CancelReason::Company)->create();
        Application::factory()->for($posting)->rejected()->create();

        $response = $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/duplicate", ['work_date' => '2026-12-01'])
            ->assertCreated()
            ->assertJsonPath('data.status', ['value' => 'open', 'label' => '募集中'])
            ->assertJsonPath('data.applications_count', 0)
            ->assertJsonPath('data.can', ['edit' => true, 'close' => true, 'cancel' => true, 'apply' => true]);

        $this->assertSame(0, Application::query()->where('job_posting_id', $response->json('data.id'))->count());
        $this->assertSame(2, Application::query()->where('job_posting_id', $posting->id)->count());
        $this->assertSame(JobPostingStatus::Cancelled, $posting->fresh()->status);
    }

    public function test_複製の勤務日の形式が違うと422(): void
    {
        $posting = JobPosting::factory()->create();

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/duplicate", ['work_date' => '2026/12/01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_date' => '勤務日は YYYY-MM-DD の形式で入力してください。']);

        $this->assertDatabaseCount('job_postings', 1);
    }

    public function test_date_toだけなら当日を含みそれ以前が出る(): void
    {
        $before = JobPosting::factory()->onDate('2026-10-30')->create();
        $same = JobPosting::factory()->onDate('2026-10-31')->create();
        JobPosting::factory()->onDate('2026-11-01')->create();

        $response = $this->actingAsAdmin()->getJson('/api/job-postings?date_to=2026-10-31')->assertOk();

        $this->assertSame([$before->id, $same->id], array_column($response->json('data'), 'id'));
    }

    public function test_date_fromだけなら当日を含みそれ以降が出る(): void
    {
        JobPosting::factory()->onDate('2026-09-30')->create();
        $same = JobPosting::factory()->onDate('2026-10-01')->create();
        $after = JobPosting::factory()->onDate('2026-10-02')->create();

        $response = $this->actingAsAdmin()->getJson('/api/job-postings?date_from=2026-10-01')->assertOk();

        $this->assertSame([$same->id, $after->id], array_column($response->json('data'), 'id'));
    }

    public function test_date_fromとdate_toが同じ日ならその日だけ出る(): void
    {
        JobPosting::factory()->onDate('2026-10-14')->create();
        $hit = JobPosting::factory()->onDate('2026-10-15')->create();
        JobPosting::factory()->onDate('2026-10-16')->create();

        $response = $this->actingAsAdmin()->getJson('/api/job-postings?date_from=2026-10-15&date_to=2026-10-15')->assertOk();

        $this->assertSame([$hit->id], array_column($response->json('data'), 'id'));
    }

    public function test_作成と編集と複製で入れた勤務日もdate_toの当日で絞り込める(): void
    {
        // 保存形式が "Y-m-d" にそろっていないと、文字列比較の <= で当日が漏れる（JobPosting::workDate() の確認）
        $this->actingAsAdmin();
        $created = $this->postJson('/api/job-postings', $this->payload(['work_date' => '2026-10-31']))->assertCreated()->json('data.id');
        $edited = JobPosting::factory()->onDate('2026-09-01')->create();
        $this->putJson("/api/job-postings/{$edited->id}", $this->payload(['location_id' => $edited->location_id, 'work_date' => '2026-10-31']))->assertOk();
        // 複製は元と別の日でなければならないので、別日の募集から 10-31 に複製する
        $source = JobPosting::factory()->onDate('2026-09-02')->create();
        $copied = $this->postJson("/api/job-postings/{$source->id}/duplicate", ['work_date' => '2026-10-31'])->assertCreated()->json('data.id');

        $response = $this->getJson('/api/job-postings?date_from=2026-10-31&date_to=2026-10-31')->assertOk();

        $this->assertEqualsCanonicalizing([$created, $edited->id, $copied], array_column($response->json('data'), 'id'));
    }

    public function test_同じ勤務日は開始時刻の昇順(): void
    {
        $late = JobPosting::factory()->onDate('2026-10-10')->create(['start_time' => '13:00', 'end_time' => '18:00']);
        $early = JobPosting::factory()->onDate('2026-10-10')->create(['start_time' => '08:00', 'end_time' => '12:00']);

        $response = $this->actingAsAdmin()->getJson('/api/job-postings')->assertOk();

        $this->assertSame([$early->id, $late->id], array_column($response->json('data'), 'id'));
    }

    public function test_他社の拠点_i_dで絞り込むと0件(): void
    {
        JobPosting::factory()->create();
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => JobPosting::factory()->create());
        Context::forget('company_id');

        $this->actingAsAdmin()
            ->getJson("/api/job-postings?location_id={$theirs->location_id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_一覧はper_pageでページングされる(): void
    {
        JobPosting::factory()->count(3)->create();

        $this->actingAsAdmin()
            ->getJson('/api/job-postings?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_複製の勤務日が元の募集と同じだと422(): void
    {
        $posting = JobPosting::factory()->onDate('2026-10-10')->create();

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/duplicate", ['work_date' => '2026-10-10'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_date' => '勤務日は元の募集と別の日にしてください。']);

        $this->assertDatabaseCount('job_postings', 1);
    }

    public function test_時給が今の最低賃金未満の募集は複製できず422(): void
    {
        // 作成した後に最低賃金が上がった募集（作成時の検証は通っている）
        $posting = JobPosting::factory()->create(['hourly_wage' => 1200]);
        config(['myteam.min_wage' => 1226]);

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/duplicate", ['work_date' => '2026-12-24'])
            ->assertUnprocessable()
            ->assertJsonPath('message', '時給が最低賃金（1226円）未満のため複製できません。')
            ->assertJsonPath('errors.hourly_wage.0', '時給が最低賃金（1226円）未満のため複製できません。');

        $this->assertDatabaseCount('job_postings', 1);
    }

    public function test_時給と交通費と募集人数は上限を超えると422で上限ちょうどなら作成できる(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/job-postings', $this->payload(['hourly_wage' => 100001, 'transport_allowance' => 100001, 'capacity' => 1001]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hourly_wage', 'transport_allowance', 'capacity']);

        $this->assertDatabaseCount('job_postings', 0);

        $this->postJson('/api/job-postings', $this->payload(['hourly_wage' => 100000, 'transport_allowance' => 100000, 'capacity' => 1000]))
            ->assertCreated();
    }

    public function test_date_toがdate_fromより前だと422(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/job-postings?date_from=2026-10-10&date_to=2026-10-09')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to' => '勤務日（まで）には、勤務日（から）以降の日付を指定してください。']);
    }
}
