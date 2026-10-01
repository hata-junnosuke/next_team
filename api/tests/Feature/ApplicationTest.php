<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Events\ApplicationConfirmed;
use App\Models\Application;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Worker;
use App\Services\ApplicationService;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * F-09〜F-14 応募・確定（docs/01 6.5、6.4 の applicable-workers、4.5、4.6）。
 * 定員超過の拒否は要件 7 章の必須テスト。
 */
class ApplicationTest extends TestCase
{
    // ---- 承認（F-11）と定員 ----

    public function test_定員に達していると承認できない(): void
    {
        $posting = JobPosting::factory()->create(['capacity' => 1]);
        Application::factory()->for($posting)->confirmed()->create();
        $applied = Application::factory()->for($posting)->create();

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$applied->id}/confirm")
            ->assertUnprocessable()
            ->assertExactJson(['message' => '定員に達しているため承認できません。', 'errors' => []]);

        $this->assertSame(ApplicationStatus::Applied, $applied->fresh()->status);
        $this->assertNull($applied->fresh()->confirmed_at);
    }

    public function test_定員ちょうどまでは承認でき次の1件は拒否される(): void
    {
        $posting = JobPosting::factory()->create(['capacity' => 2]);
        [$first, $second, $third] = Application::factory()->for($posting)->count(3)->create()->all();

        $this->actingAsAdmin()->postJson("/api/applications/{$first->id}/confirm")->assertOk();
        $this->postJson("/api/applications/{$second->id}/confirm")->assertOk();
        $this->postJson("/api/applications/{$third->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('message', '定員に達しているため承認できません。');

        $this->assertSame(2, $posting->confirmedApplications()->count());
    }

    public function test_キャンセルで1枠空くと承認できる(): void
    {
        $posting = JobPosting::factory()->create(['capacity' => 1]);
        $confirmed = Application::factory()->for($posting)->confirmed()->create();
        $applied = Application::factory()->for($posting)->create();

        $this->actingAsAdmin()->postJson("/api/applications/{$applied->id}/confirm")->assertUnprocessable();

        $this->postJson("/api/applications/{$confirmed->id}/cancel", ['reason' => 'worker'])->assertOk();

        $this->postJson("/api/applications/{$applied->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', ['value' => 'confirmed', 'label' => '確定']);
    }

    public function test_却下は定員に影響しない(): void
    {
        $posting = JobPosting::factory()->create(['capacity' => 1]);
        Application::factory()->for($posting)->rejected()->count(2)->create();
        $applied = Application::factory()->for($posting)->create();

        $this->actingAsAdmin()->postJson("/api/applications/{$applied->id}/confirm")->assertOk();
    }

    public function test_締切後も応募中は承認できる(): void
    {
        $posting = JobPosting::factory()->closed()->create();
        $applied = Application::factory()->for($posting)->create();

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$applied->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'confirmed');
    }

    public function test_却下後は承認できない(): void
    {
        $rejected = Application::factory()->rejected()->create();

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$rejected->id}/confirm")
            ->assertUnprocessable()
            ->assertExactJson(['message' => '却下された応募は承認できません。', 'errors' => []]);

        $this->assertSame(ApplicationStatus::Rejected, $rejected->fresh()->status);
    }

    public function test_却下済みの応募は定員が埋まっていても状態の理由で拒否される(): void
    {
        $posting = JobPosting::factory()->create(['capacity' => 1]);
        Application::factory()->for($posting)->confirmed()->create();
        $rejected = Application::factory()->for($posting)->rejected()->create();

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$rejected->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('message', '却下された応募は承認できません。');
    }

    public function test_確定済みとキャンセル済みは承認できない(): void
    {
        $confirmed = Application::factory()->confirmed()->create();
        $cancelled = Application::factory()->cancelled()->create();

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$confirmed->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'この応募はすでに確定しています。');
        $this->postJson("/api/applications/{$cancelled->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'キャンセルされた応募は承認できません。');
    }

    public function test_確定時に時給と交通費が固定され確定日時が記録される(): void
    {
        Carbon::setTestNow('2026-10-05 10:30:00');
        $posting = JobPosting::factory()->create(['hourly_wage' => 1300, 'transport_allowance' => 500]);
        $applied = Application::factory()->for($posting)->create();

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$applied->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.confirmed_at', '2026-10-05T10:30:00+09:00')
            ->assertJsonPath('data.confirmed_hourly_wage', 1300)
            ->assertJsonPath('data.confirmed_transport_allowance', 500);

        // 確定の後に募集の時給・交通費を変えても、確定時の値は変わらない
        $posting->update(['hourly_wage' => 1500, 'transport_allowance' => 800]);

        $this->assertDatabaseHas('applications', [
            'id' => $applied->id,
            'status' => 'confirmed',
            'confirmed_hourly_wage' => 1300,
            'confirmed_transport_allowance' => 500,
        ]);
        $this->assertSame('2026-10-05 10:30:00', $applied->fresh()->confirmed_at->format('Y-m-d H:i:s'));
    }

    public function test_承認すると_application_confirmedがコミット後に発火する(): void
    {
        $this->assertContains(ShouldDispatchAfterCommit::class, class_implements(ApplicationConfirmed::class));

        Event::fake([ApplicationConfirmed::class]);
        $applied = Application::factory()->create();

        $this->actingAsAdmin()->postJson("/api/applications/{$applied->id}/confirm")->assertOk();

        Event::assertDispatchedTimes(ApplicationConfirmed::class, 1);
        Event::assertDispatched(ApplicationConfirmed::class, fn (ApplicationConfirmed $e) => $e->applicationId === $applied->id);
    }

    public function test_承認がロールバックされると_application_confirmedは発火しない(): void
    {
        Event::fake([ApplicationConfirmed::class]);
        $applied = Application::factory()->create();

        DB::beginTransaction();
        try {
            app(ApplicationService::class)->confirm($applied);
        } finally {
            DB::rollBack();
        }

        Event::assertNotDispatched(ApplicationConfirmed::class);
        $this->assertSame(ApplicationStatus::Applied, $applied->fresh()->status);
    }

    public function test_定員超過で拒否されたときは_application_confirmedは発火しない(): void
    {
        Event::fake([ApplicationConfirmed::class]);
        $posting = JobPosting::factory()->create(['capacity' => 1]);
        Application::factory()->for($posting)->confirmed()->create();
        $applied = Application::factory()->for($posting)->create();

        $this->actingAsAdmin()->postJson("/api/applications/{$applied->id}/confirm")->assertUnprocessable();

        Event::assertNotDispatched(ApplicationConfirmed::class);
    }

    public function test_中止された募集に応募中が残っていても承認できない(): void
    {
        // 中止は応募中を却下にするので通常は残らない。データの不整合などで残った状況をファクトリで直接作る
        $posting = JobPosting::factory()->cancelled()->create();
        $applied = Application::factory()->for($posting)->create();

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$applied->id}/confirm")
            ->assertUnprocessable()
            ->assertExactJson(['message' => '中止された募集の応募は承認できません。', 'errors' => []]);

        $this->assertSame(ApplicationStatus::Applied, $applied->fresh()->status);
    }

    public function test_無効にしたワーカーの応募中も承認できる(): void
    {
        // 無効化は新規応募を止めるだけで、既存の応募は影響を受けない（docs/01 4.6）
        $worker = Worker::factory()->create();
        $applied = Application::factory()->for($worker)->create();
        $worker->update(['is_active' => false]);

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$applied->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'confirmed');
    }

    public function test_却下済みとキャンセル済みは却下できない(): void
    {
        $rejected = Application::factory()->rejected()->create();
        $cancelled = Application::factory()->cancelled()->create();

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$rejected->id}/reject")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'この応募はすでに却下されています。');
        $this->postJson("/api/applications/{$cancelled->id}/reject")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'キャンセルされた応募は却下できません。');
    }

    // ---- 却下（F-12）とキャンセル（F-13） ----

    public function test_応募中は却下できる(): void
    {
        $applied = Application::factory()->create();

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$applied->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', ['value' => 'rejected', 'label' => '却下'])
            ->assertJsonPath('data.can', ['confirm' => false, 'reject' => false, 'cancel' => false, 'attend' => false]);

        $this->assertSame(ApplicationStatus::Rejected, $applied->fresh()->status);
    }

    public function test_確定済みは却下できない(): void
    {
        $confirmed = Application::factory()->confirmed()->create();

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$confirmed->id}/reject")
            ->assertUnprocessable()
            ->assertJsonPath('message', '確定した応募は却下できません。');
    }

    public function test_キャンセルは確定済みのみで理由区分が必須(): void
    {
        $confirmed = Application::factory()->confirmed()->create();
        $applied = Application::factory()->create();
        $this->actingAsAdmin();

        // 理由なし・不正な理由は 422（errors.reason）
        $this->postJson("/api/applications/{$confirmed->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/applications/{$confirmed->id}/cancel", ['reason' => 'other'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
        $this->assertSame(ApplicationStatus::Confirmed, $confirmed->fresh()->status);

        // 応募中はキャンセルできない（確定済みのみ）
        $this->postJson("/api/applications/{$applied->id}/cancel", ['reason' => 'company'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => '確定済みの応募だけキャンセルできます。', 'errors' => []]);
        $this->assertSame(ApplicationStatus::Applied, $applied->fresh()->status);

        Carbon::setTestNow('2026-10-06 18:00:00');
        $this->postJson("/api/applications/{$confirmed->id}/cancel", ['reason' => 'company'])
            ->assertOk()
            ->assertJsonPath('data.status', ['value' => 'cancelled', 'label' => 'キャンセル'])
            ->assertJsonPath('data.cancel_reason', ['value' => 'company', 'label' => '企業都合'])
            ->assertJsonPath('data.cancelled_at', '2026-10-06T18:00:00+09:00');

        $fresh = $confirmed->fresh();
        $this->assertSame(CancelReason::Company, $fresh->cancel_reason);
        // 確定時の値は残す（いつ・いくらで確定していたかの記録）
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertNotNull($fresh->confirmed_hourly_wage);

        // キャンセル済みはもう一度キャンセルできない
        $this->postJson("/api/applications/{$confirmed->id}/cancel", ['reason' => 'worker'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'この応募はすでにキャンセルされています。');
    }

    // ---- 応募の代行入力（F-09） ----

    public function test_応募すると201で応募中の応募が返る(): void
    {
        $posting = JobPosting::factory()->create();
        $worker = Worker::factory()->create(['name' => '山田太郎']);

        $response = $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => $worker->id])
            ->assertCreated();

        $application = Application::query()->sole();
        $response->assertExactJson(['data' => [
            'id' => $application->id,
            'worker' => ['id' => $worker->id, 'name' => '山田太郎'],
            'status' => ['value' => 'applied', 'label' => '応募中'],
            'confirmed_at' => null,
            'confirmed_hourly_wage' => null,
            'confirmed_transport_allowance' => null,
            'cancel_reason' => null,
            'cancelled_at' => null,
            'attendance' => null,
            'can' => ['confirm' => true, 'reject' => true, 'cancel' => false, 'attend' => false],
        ]]);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'company_id' => $this->company->id,
            'job_posting_id' => $posting->id,
            'worker_id' => $worker->id,
            'status' => 'applied',
        ]);
    }

    public function test_同一ワーカーは二重応募できずerrors_worker_idに入る(): void
    {
        $posting = JobPosting::factory()->create();
        $worker = Worker::factory()->create();
        // 却下済みでも同じ募集にはもう応募できない（UNIQUE (job_posting_id, worker_id)）
        Application::factory()->for($posting)->for($worker)->rejected()->create();

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => $worker->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.worker_id', ['このワーカーはすでにこの募集に応募しています。']);

        $this->assertSame(1, Application::query()->count());
    }

    public function test_unique違反の競合も422でerrors_worker_idに入る(): void
    {
        $posting = JobPosting::factory()->create();
        $worker = Worker::factory()->create();

        // exists() の確認と INSERT の間に、別のリクエストが同じ応募を入れた状況を作る
        Application::creating(function (Application $application) use ($posting, $worker) {
            DB::table('applications')->insert([
                'company_id' => $this->company->id,
                'job_posting_id' => $posting->id,
                'worker_id' => $worker->id,
                'status' => 'applied',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => $worker->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.worker_id', ['このワーカーはすでにこの募集に応募しています。']);
    }

    public function test_無効ワーカーは応募できない(): void
    {
        $posting = JobPosting::factory()->create();
        $worker = Worker::factory()->inactive()->create();

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => $worker->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.worker_id', ['無効のワーカーは応募できません。']);

        $this->assertSame(0, Application::query()->count());
    }

    public function test_無効にしても既存の確定は影響を受けない(): void
    {
        $worker = Worker::factory()->create();
        $confirmed = Application::factory()->for($worker)->confirmed()->create();

        $this->actingAsAdmin()->patchJson("/api/workers/{$worker->id}/active", ['is_active' => false])->assertOk();

        $this->assertSame(ApplicationStatus::Confirmed, $confirmed->fresh()->status);
        $this->postJson("/api/applications/{$confirmed->id}/cancel", ['reason' => 'worker'])->assertOk();
    }

    public function test_募集中でない募集には応募できない(): void
    {
        $worker = Worker::factory()->create();
        $closed = JobPosting::factory()->closed()->create();
        $cancelled = JobPosting::factory()->cancelled()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/job-postings/{$closed->id}/applications", ['worker_id' => $worker->id])
            ->assertUnprocessable()
            ->assertExactJson(['message' => '締め切られた募集には応募できません。', 'errors' => []]);
        $this->postJson("/api/job-postings/{$cancelled->id}/applications", ['worker_id' => $worker->id])
            ->assertUnprocessable()
            ->assertExactJson(['message' => '中止された募集には応募できません。', 'errors' => []]);

        $this->assertSame(0, Application::query()->count());
    }

    public function test_ワーカーの指定がないか存在しないと422(): void
    {
        $posting = JobPosting::factory()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/job-postings/{$posting->id}/applications", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['worker_id']);
        $this->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['worker_id']);
    }

    public function test_他社のワーカーは応募できず422(): void
    {
        $posting = JobPosting::factory()->create();
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Worker::factory()->create());

        $this->actingAsAdmin()
            ->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => $theirs->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['worker_id']);

        $this->assertSame(0, Application::query()->count());
    }

    // ---- 一覧と応募できるワーカー ----

    public function test_applicable_workersに無効と応募済みは出ない(): void
    {
        $posting = JobPosting::factory()->create();
        $available = Worker::factory()->create(['name' => 'あ候補']);
        Worker::factory()->inactive()->create(['name' => 'い無効']);
        $applied = Worker::factory()->create(['name' => 'う応募済み']);
        Application::factory()->for($posting)->for($applied)->rejected()->create();
        // 別の募集に応募しているだけなら出る
        $elsewhere = Worker::factory()->create(['name' => 'え別の募集']);
        Application::factory()->for($elsewhere)->create();
        // 他社のワーカーは出ない
        CurrentCompany::runAs(Company::factory()->create()->id, fn () => Worker::factory()->create(['name' => 'お他社']));

        $this->actingAsAdmin()
            ->getJson("/api/job-postings/{$posting->id}/applicable-workers")
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $available->id, 'name' => 'あ候補'],
                ['id' => $elsewhere->id, 'name' => 'え別の募集'],
            ]]);
    }

    public function test_応募一覧に状態と勤怠と可否が入る(): void
    {
        $posting = JobPosting::factory()->create(['hourly_wage' => 1300, 'transport_allowance' => 500]);
        $applied = Application::factory()->for($posting)->create();
        $confirmed = Application::factory()->for($posting)->confirmed()->create();
        Attendance::factory()->for($confirmed)->create();
        $cancelled = Application::factory()->for($posting)->cancelled(CancelReason::Worker)->create();
        Application::factory()->create();   // 別の募集の応募は出ない

        $response = $this->actingAsAdmin()
            ->getJson("/api/job-postings/{$posting->id}/applications")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $applied->id)
            ->assertJsonPath('data.0.attendance', null)
            ->assertJsonPath('data.0.can', ['confirm' => true, 'reject' => true, 'cancel' => false, 'attend' => false])
            ->assertJsonPath('data.1.id', $confirmed->id)
            ->assertJsonPath('data.1.confirmed_hourly_wage', 1300)
            ->assertJsonPath('data.1.can', ['confirm' => false, 'reject' => false, 'cancel' => true, 'attend' => true])
            ->assertJsonPath('data.2.id', $cancelled->id)
            ->assertJsonPath('data.2.cancel_reason', ['value' => 'worker', 'label' => 'ワーカー都合'])
            ->assertJsonPath('data.2.can', ['confirm' => false, 'reject' => false, 'cancel' => false, 'attend' => false]);

        // 勤怠は AttendanceResource の形（docs/01 6.6）
        $this->assertSame(
            ['clock_in', 'clock_out', 'is_overnight', 'break_minutes', 'worked_minutes', 'wage_pay', 'transport_allowance', 'total_pay'],
            array_keys($response->json('data.1.attendance')),
        );
        $this->assertSame(500, $response->json('data.1.attendance.transport_allowance'));
    }

    public function test_canは状態だけを見て定員は見ない(): void
    {
        $posting = JobPosting::factory()->create(['capacity' => 1]);
        Application::factory()->for($posting)->confirmed()->create();
        Application::factory()->for($posting)->create();

        $this->actingAsAdmin()
            ->getJson("/api/job-postings/{$posting->id}/applications")
            ->assertOk()
            ->assertJsonPath('data.1.can.confirm', true);
    }

    public function test_応募一覧はn_1にならない(): void
    {
        $posting = JobPosting::factory()->create();
        $make = function () use ($posting) {
            Application::factory()->for($posting)->create();
            Attendance::factory()->for(Application::factory()->for($posting)->confirmed()->create())->create();
        };
        $make();
        $this->actingAsAdmin()->getJson("/api/job-postings/{$posting->id}/applications")->assertOk();   // 認証などの初回分を済ませる

        DB::enableQueryLog();
        $this->getJson("/api/job-postings/{$posting->id}/applications")->assertOk()->assertJsonCount(2, 'data');
        $few = count(DB::getQueryLog());

        $make();
        $make();
        DB::flushQueryLog();
        $this->getJson("/api/job-postings/{$posting->id}/applications")->assertOk()->assertJsonCount(6, 'data');
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($few, $many);
    }

    // ---- テナント分離 ----

    public function test_他社の応募は404(): void
    {
        $other = Company::factory()->create();
        [$theirPosting, $theirApplication, $theirConfirmed] = CurrentCompany::runAs($other->id, function () {
            $posting = JobPosting::factory()->create();

            return [
                $posting,
                Application::factory()->for($posting)->create(),
                Application::factory()->for($posting)->confirmed()->create(),
            ];
        });
        $mine = Worker::factory()->create();
        // TestCase の setUp で入れた企業を消し、ミドルウェアが企業を決めるところから通す（learnings）
        Context::forget('company_id');

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$theirApplication->id}/confirm")
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);
        $this->postJson("/api/applications/{$theirApplication->id}/reject")->assertNotFound();
        $this->postJson("/api/applications/{$theirConfirmed->id}/cancel", ['reason' => 'company'])->assertNotFound();
        $this->getJson("/api/job-postings/{$theirPosting->id}/applications")->assertNotFound();
        $this->getJson("/api/job-postings/{$theirPosting->id}/applicable-workers")->assertNotFound();
        $this->postJson("/api/job-postings/{$theirPosting->id}/applications", ['worker_id' => $mine->id])->assertNotFound();

        CurrentCompany::set($other->id);
        $this->assertSame(ApplicationStatus::Applied, $theirApplication->fresh()->status);
        $this->assertSame(ApplicationStatus::Confirmed, $theirConfirmed->fresh()->status);
    }

    // ---- テスト補強（P2-04 tester） ----

    public function test_定員1は1件目を承認でき2件目は拒否される(): void
    {
        $posting = JobPosting::factory()->create(['capacity' => 1]);
        [$first, $second] = Application::factory()->for($posting)->count(2)->create()->all();

        $this->actingAsAdmin()->postJson("/api/applications/{$first->id}/confirm")->assertOk();
        $this->postJson("/api/applications/{$second->id}/confirm")
            ->assertUnprocessable()
            ->assertExactJson(['message' => '定員に達しているため承認できません。', 'errors' => []]);

        $this->assertSame(1, $posting->confirmedApplications()->count());
        $this->assertSame(ApplicationStatus::Applied, $second->fresh()->status);
    }

    public function test_キャンセルで空くのは1枠だけで再承認後はまた定員エラーになる(): void
    {
        // 動線 6・7 の形（定員 2 で、企業都合のキャンセル 1 件につき 1 枠だけ空く）
        $posting = JobPosting::factory()->create(['capacity' => 2]);
        [$a, $b, $c, $d] = Application::factory()->for($posting)->count(4)->create()->all();
        $this->actingAsAdmin();

        $this->postJson("/api/applications/{$a->id}/confirm")->assertOk();
        $this->postJson("/api/applications/{$b->id}/confirm")->assertOk();
        $this->postJson("/api/applications/{$c->id}/confirm")->assertUnprocessable();

        $this->postJson("/api/applications/{$a->id}/cancel", ['reason' => 'company'])->assertOk();

        $this->postJson("/api/applications/{$c->id}/confirm")->assertOk();
        $this->postJson("/api/applications/{$d->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('message', '定員に達しているため承認できません。');

        $this->assertSame(2, $posting->confirmedApplications()->count());
        // キャンセル済みの応募はもう承認できない（終端）
        $this->postJson("/api/applications/{$a->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'キャンセルされた応募は承認できません。');
    }

    public function test_確定後に募集の時給をapiで変えても確定時の値と報酬は変わらない(): void
    {
        $posting = JobPosting::factory()->create(['hourly_wage' => 1300, 'transport_allowance' => 500]);
        $applied = Application::factory()->for($posting)->create();
        $this->actingAsAdmin()->postJson("/api/applications/{$applied->id}/confirm")->assertOk();

        $this->putJson("/api/job-postings/{$posting->id}", [
            'location_id' => $posting->location_id,
            'job_title' => $posting->job_title,
            'work_date' => $posting->work_date->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => $posting->capacity,
            'hourly_wage' => 1500,
            'transport_allowance' => 800,
        ])->assertOk()->assertJsonPath('data.hourly_wage', 1500);

        $this->getJson("/api/job-postings/{$posting->id}/applications")
            ->assertOk()
            ->assertJsonPath('data.0.confirmed_hourly_wage', 1300)
            ->assertJsonPath('data.0.confirmed_transport_allowance', 500);

        // 報酬は確定時の値で計算される（7h × 1,300 + 500 = 9,600 円。募集の新しい値なら 7h × 1,500 + 800 = 11,300 円）
        $this->putJson("/api/applications/{$applied->id}/attendance", [
            'clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60,
        ])
            ->assertOk()
            ->assertJsonPath('data.wage_pay', 9100)
            ->assertJsonPath('data.transport_allowance', 500)
            ->assertJsonPath('data.total_pay', 9600);
    }

    public function test_中止された募集の応募は承認も却下もキャンセルもできない(): void
    {
        $posting = JobPosting::factory()->create(['capacity' => 2]);
        $applied = Application::factory()->for($posting)->create();
        $confirmed = Application::factory()->for($posting)->confirmed()->create();
        $this->actingAsAdmin();

        // 中止で、応募中は却下に、確定は企業都合キャンセルに自動遷移する（F-07）
        $this->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();

        $this->postJson("/api/applications/{$applied->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('message', '却下された応募は承認できません。');
        $this->postJson("/api/applications/{$applied->id}/reject")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'この応募はすでに却下されています。');
        $this->postJson("/api/applications/{$confirmed->id}/cancel", ['reason' => 'worker'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'この応募はすでにキャンセルされています。');
        $this->postJson("/api/applications/{$confirmed->id}/confirm")->assertUnprocessable();

        $this->assertSame(ApplicationStatus::Rejected, $applied->fresh()->status);
        $this->assertSame(CancelReason::Company, $confirmed->fresh()->cancel_reason);
    }

    public function test_apiで締め切った後の新規応募は422で応募は増えない(): void
    {
        $posting = JobPosting::factory()->create();
        $worker = Worker::factory()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/job-postings/{$posting->id}/close")->assertOk();

        $this->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => $worker->id])
            ->assertUnprocessable()
            ->assertExactJson(['message' => '締め切られた募集には応募できません。', 'errors' => []]);
        $this->assertSame(0, Application::query()->count());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidReasons(): iterable
    {
        yield 'null' => [null];
        yield '空文字' => [''];
        yield '大文字' => ['COMPANY'];
        yield 'ラベル' => ['企業都合'];
        yield '状態の値' => ['cancelled'];
        yield '整数' => [1];
        yield '真偽値' => [true];
        yield '配列' => [['company']];
    }

    #[DataProvider('invalidReasons')]
    public function test_キャンセル理由がcompanyとworker以外なら422でerrors_reasonに入る(mixed $reason): void
    {
        $confirmed = Application::factory()->confirmed()->create();

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$confirmed->id}/cancel", ['reason' => $reason])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason'])
            ->assertJsonStructure(['message', 'errors' => ['reason']]);

        $this->assertSame(ApplicationStatus::Confirmed, $confirmed->fresh()->status);
        $this->assertNull($confirmed->fresh()->cancelled_at);
    }

    public function test_キャンセル理由はworkerでも受け付けワーカー都合が記録される(): void
    {
        $confirmed = Application::factory()->confirmed()->create();

        $this->actingAsAdmin()
            ->postJson("/api/applications/{$confirmed->id}/cancel", ['reason' => 'worker'])
            ->assertOk()
            ->assertJsonPath('data.cancel_reason', ['value' => 'worker', 'label' => 'ワーカー都合']);

        $this->assertDatabaseHas('applications', ['id' => $confirmed->id, 'status' => 'cancelled', 'cancel_reason' => 'worker']);
    }

    public function test_ワーカーidが整数でないと422でerrors_worker_idに入る(): void
    {
        $posting = JobPosting::factory()->create();
        $this->actingAsAdmin();

        foreach (['abc', '1.5', [1]] as $value) {
            $this->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => $value])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['worker_id']);
        }
        $this->assertSame(0, Application::query()->count());
    }

    public function test_承認とキャンセルのレスポンスはdocs01の6_5の形で勤怠が入る(): void
    {
        $posting = JobPosting::factory()->create(['transport_allowance' => 500]);
        $applied = Application::factory()->for($posting)->create();
        $this->actingAsAdmin();

        $this->postJson("/api/applications/{$applied->id}/confirm")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'worker' => ['id', 'name'], 'status' => ['value', 'label'], 'confirmed_at',
                'confirmed_hourly_wage', 'confirmed_transport_allowance', 'cancel_reason', 'cancelled_at', 'attendance',
                'can' => ['confirm', 'reject', 'cancel', 'attend'],
            ]])
            ->assertJsonPath('data.attendance', null)
            ->assertJsonPath('data.can', ['confirm' => false, 'reject' => false, 'cancel' => true, 'attend' => true]);

        $this->putJson("/api/applications/{$applied->id}/attendance", [
            'clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60,
        ])->assertOk();

        // 勤怠の後にキャンセルしても勤怠の行は残り、AttendanceResource の形で返る（docs/01 6.6）
        $this->postJson("/api/applications/{$applied->id}/cancel", ['reason' => 'company'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['attendance' => [
                'clock_in', 'clock_out', 'is_overnight', 'break_minutes', 'worked_minutes', 'wage_pay', 'transport_allowance', 'total_pay',
            ]]])
            ->assertJsonPath('data.attendance.worked_minutes', 420)
            ->assertJsonPath('data.attendance.is_overnight', false)
            ->assertJsonPath('data.attendance.transport_allowance', 500)
            ->assertJsonPath('data.attendance.total_pay', 9600)
            ->assertJsonPath('data.can.attend', false);
    }

    public function test_応募のエンドポイントは未ログインなら401(): void
    {
        $posting = JobPosting::factory()->create();
        $application = Application::factory()->for($posting)->confirmed()->create();
        $worker = Worker::factory()->create();

        $this->getJson("/api/job-postings/{$posting->id}/applications")->assertUnauthorized();
        $this->getJson("/api/job-postings/{$posting->id}/applicable-workers")->assertUnauthorized();
        $this->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => $worker->id])->assertUnauthorized();
        $this->postJson("/api/applications/{$application->id}/confirm")->assertUnauthorized();
        $this->postJson("/api/applications/{$application->id}/reject")->assertUnauthorized();
        $this->postJson("/api/applications/{$application->id}/cancel", ['reason' => 'company'])->assertUnauthorized();

        $this->assertSame(1, Application::query()->count());
        $this->assertSame(ApplicationStatus::Confirmed, $application->fresh()->status);
    }

    public function test_他社の応募は理由が不正でも検証より先に404になる(): void
    {
        $other = Company::factory()->create();
        [$theirPosting, $theirConfirmed] = CurrentCompany::runAs($other->id, function () {
            $posting = JobPosting::factory()->create();

            return [$posting, Application::factory()->for($posting)->confirmed()->create()];
        });
        Context::forget('company_id');

        // ルートモデルバインディングが先に 404 にする（他社の応募の存在を 422 で漏らさない）
        $this->actingAsAdmin()
            ->postJson("/api/applications/{$theirConfirmed->id}/cancel", ['reason' => 'other'])
            ->assertNotFound();
        $this->postJson("/api/job-postings/{$theirPosting->id}/applications", [])->assertNotFound();
        $this->putJson("/api/applications/{$theirConfirmed->id}/attendance", [
            'clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60,
        ])->assertNotFound();
    }

    public function test_他社の募集の応募一覧に自社の応募は混ざらず自社の一覧に他社の応募は出ない(): void
    {
        $posting = JobPosting::factory()->create();
        $mine = Application::factory()->for($posting)->create();
        CurrentCompany::runAs(Company::factory()->create()->id, function () {
            Application::factory()->count(2)->create();
        });

        $this->actingAsAdmin()
            ->getJson("/api/job-postings/{$posting->id}/applications")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_application_confirmedはモデルではなく応募idだけを持つ(): void
    {
        $class = new \ReflectionClass(ApplicationConfirmed::class);
        $params = $class->getConstructor()?->getParameters() ?? [];

        $this->assertCount(1, $params);
        $this->assertSame('applicationId', $params[0]->getName());
        $this->assertSame('int', (string) $params[0]->getType());
        $this->assertSame(['applicationId'], array_map(fn (\ReflectionProperty $p) => $p->getName(), $class->getProperties()));
        // SerializesModels に頼らない（docs/01 12 章「ジョブに ID を渡す」）
        $this->assertNotContains(SerializesModels::class, class_uses_recursive(ApplicationConfirmed::class));
    }

    public function test_却下とキャンセルと応募では_application_confirmedは発火しない(): void
    {
        Event::fake([ApplicationConfirmed::class]);
        $posting = JobPosting::factory()->create();
        $applied = Application::factory()->for($posting)->create();
        $confirmed = Application::factory()->for($posting)->confirmed()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/job-postings/{$posting->id}/applications", ['worker_id' => Worker::factory()->create()->id])->assertCreated();
        $this->postJson("/api/applications/{$applied->id}/reject")->assertOk();
        $this->postJson("/api/applications/{$confirmed->id}/cancel", ['reason' => 'company'])->assertOk();

        Event::assertNotDispatched(ApplicationConfirmed::class);
    }
}
