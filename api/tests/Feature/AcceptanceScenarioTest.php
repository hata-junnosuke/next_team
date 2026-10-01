<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\Worker;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Context;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 要件 5 章の受け入れ動線 10 ステップ（myteam-acceptance の M-1〜M-10 と M-11 ダッシュボード）を
 * API 経由だけで 1 本通す。docs/01 9.1 の必須テスト。
 *
 * データはすべて API で作る（ファクトリで作るのは比較用の他社のデータだけ）。
 * 本番と同じく企業はログイン中の管理者から決まることを確かめるため、ログイン前に Context を消す。
 * 勤務日 2026-09-20 がダッシュボードの「今日以降」に入るよう、時刻は 2026-09-01 10:00 に固定する。
 */
class AcceptanceScenarioTest extends TestCase
{
    /** @var array{posting: JobPosting, application: Application, location: Location, worker: Worker} */
    private array $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 9, 1)->setTime(10, 0));

        // 比較用の他社のデータ（同じ 9 月に勤怠まである）。動線の中で見えないことを確かめる
        $otherCompany = Company::factory()->create();
        $this->other = CurrentCompany::runAs($otherCompany->id, function () {
            $location = Location::factory()->create(['name' => '他社倉庫']);
            $worker = Worker::factory()->create(['name' => '他社ワーカー']);
            $posting = JobPosting::factory()->onDate('2026-09-20')->for($location)->create(['job_title' => '他社の募集']);
            $application = Application::factory()->for($posting)->for($worker)->confirmed()->create();
            Attendance::factory()->for($application)->create();
            Application::factory()->for($posting)->create();

            return compact('location', 'worker', 'posting', 'application');
        });

        Context::forget('company_id');
    }

    private function registerWorker(string $name, string $email): int
    {
        return $this->postJson('/api/workers', ['name' => $name, 'email' => $email, 'phone' => null])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');
    }

    private function apply(int $postingId, int $workerId): TestResponse
    {
        return $this->postJson("/api/job-postings/{$postingId}/applications", ['worker_id' => $workerId]);
    }

    public function test_動線10ステップが_ap_i経由で通る(): void
    {
        // ---- 1. ログインする（M-1） ----
        $this->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/login', ['email' => $this->admin->email, 'password' => 'password'])
            ->assertOk()
            ->assertExactJson(['two_factor' => false]);
        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.company.id', $this->company->id);

        // ---- 2. 拠点「東京倉庫」を登録する（M-2） ----
        $this->postJson('/api/locations', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
        $locationId = $this->postJson('/api/locations', ['name' => '東京倉庫', 'address' => '東京都江東区1-1'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'name', 'address', 'job_postings_count', 'deletable']])
            ->assertJsonPath('data.deletable', true)
            ->json('data.id');
        // 一覧には自社の拠点だけが出る（他社の「他社倉庫」は出ない）
        $this->getJson('/api/locations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', '東京倉庫');

        // ---- 3. ワーカーを 4 名登録し、1 名を無効にする（M-3） ----
        $this->postJson('/api/workers', ['name' => 'メールなし', 'email' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
        $sato = $this->registerWorker('佐藤', 'sato@example.com');
        $suzuki = $this->registerWorker('鈴木', 'suzuki@example.com');
        $takahashi = $this->registerWorker('高橋', 'takahashi@example.com');
        $tanaka = $this->registerWorker('田中', 'tanaka@example.com');
        $this->patchJson("/api/workers/{$tanaka}/active", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->getJson('/api/workers?is_active=1')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/workers?is_active=0')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $tanaka);

        // ---- 4. 募集「9/20 9:00-17:00 ピッキング 3名 時給1,300円 交通費500円」を作成する（M-4） ----
        $postingInput = [
            'location_id' => $locationId,
            'job_title' => 'ピッキング',
            'work_date' => '2026-09-20',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 3,
            'hourly_wage' => 1300,
            'transport_allowance' => 500,
        ];
        $this->postJson('/api/job-postings', ['end_time' => '08:00'] + $postingInput)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_time']);
        $postingId = $this->postJson('/api/job-postings', $postingInput)
            ->assertCreated()
            ->json('data.id');
        $this->getJson("/api/job-postings/{$postingId}")
            ->assertOk()
            ->assertJsonPath('data.location', ['id' => $locationId, 'name' => '東京倉庫'])
            ->assertJsonPath('data.job_title', 'ピッキング')
            ->assertJsonPath('data.work_date', '2026-09-20')
            ->assertJsonPath('data.start_time', '09:00')
            ->assertJsonPath('data.end_time', '17:00')
            ->assertJsonPath('data.capacity', 3)
            ->assertJsonPath('data.hourly_wage', 1300)
            ->assertJsonPath('data.transport_allowance', 500)
            ->assertJsonPath('data.status', ['value' => 'open', 'label' => '募集中']);
        // 他社の募集は詳細も 404
        $this->getJson("/api/job-postings/{$this->other['posting']->id}")->assertNotFound();

        // ---- 5. 有効ワーカー 3 名を応募させる。無効は選択肢に出ない。二重応募はエラー（M-5） ----
        $this->getJson("/api/job-postings/{$postingId}/applicable-workers")
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $sato, 'name' => '佐藤'],
                ['id' => $suzuki, 'name' => '鈴木'],
                ['id' => $takahashi, 'name' => '高橋'],
            ]]);
        $applicationIds = [];
        foreach ([$sato, $suzuki, $takahashi] as $workerId) {
            $applicationIds[$workerId] = $this->apply($postingId, $workerId)
                ->assertCreated()
                ->assertJsonPath('data.status', ['value' => 'applied', 'label' => '応募中'])
                ->json('data.id');
        }
        $this->apply($postingId, $sato)
            ->assertUnprocessable()
            ->assertJsonPath('errors.worker_id.0', 'このワーカーはすでにこの募集に応募しています。');
        $this->apply($postingId, $tanaka)
            ->assertUnprocessable()
            ->assertJsonPath('errors.worker_id.0', '無効のワーカーは応募できません。');
        // 他社のワーカーは自社の募集に応募させられない
        $this->apply($postingId, $this->other['worker']->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['worker_id']);
        $this->getJson("/api/job-postings/{$postingId}/applicable-workers")
            ->assertOk()
            ->assertExactJson(['data' => []]);

        // ---- 6. 3 名を承認する。4 人目（新規登録して応募）を承認しようとすると定員エラー（M-6） ----
        foreach ($applicationIds as $applicationId) {
            $this->postJson("/api/applications/{$applicationId}/confirm")
                ->assertOk()
                ->assertJsonPath('data.status', ['value' => 'confirmed', 'label' => '確定'])
                ->assertJsonPath('data.confirmed_hourly_wage', 1300)
                ->assertJsonPath('data.confirmed_transport_allowance', 500);
        }
        $ito = $this->registerWorker('伊藤', 'ito@example.com');
        $itoApplicationId = $this->apply($postingId, $ito)->assertCreated()->json('data.id');
        $this->postJson("/api/applications/{$itoApplicationId}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('message', '定員に達しているため承認できません。');
        $this->getJson("/api/job-postings/{$postingId}")
            ->assertJsonPath('data.applications_count', 4)
            ->assertJsonPath('data.confirmed_count', 3);
        // 他社の応募は承認できない（404）
        $this->postJson("/api/applications/{$this->other['application']->id}/confirm")->assertNotFound();

        // ---- 7. 1 名を企業都合でキャンセルする → 1 枠空き、4 人目を承認できる（M-7） ----
        $this->postJson("/api/applications/{$applicationIds[$sato]}/cancel", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/applications/{$applicationIds[$sato]}/cancel", ['reason' => 'company'])
            ->assertOk()
            ->assertJsonPath('data.status', ['value' => 'cancelled', 'label' => 'キャンセル'])
            ->assertJsonPath('data.cancel_reason', ['value' => 'company', 'label' => '企業都合']);
        $this->getJson("/api/job-postings/{$postingId}")->assertJsonPath('data.confirmed_count', 2);
        $this->postJson("/api/applications/{$itoApplicationId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'confirmed');
        $this->getJson("/api/job-postings/{$postingId}")->assertJsonPath('data.confirmed_count', 3);

        // ---- 8. 確定 3 名に勤怠を入力する（9:00-17:00 休憩 60 分）（M-8） ----
        $confirmedIds = [$applicationIds[$suzuki], $applicationIds[$takahashi], $itoApplicationId];
        $this->putJson("/api/applications/{$confirmedIds[0]}/attendance", [
            'clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 500,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['break_minutes']);
        // キャンセルした佐藤には入力できない
        $this->putJson("/api/applications/{$applicationIds[$sato]}/attendance", [
            'clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60,
        ])->assertUnprocessable();

        foreach ($confirmedIds as $applicationId) {
            // ---- 9. 報酬が 7h × 1,300 + 500 = 9,600 円（M-9） ----
            $this->putJson("/api/applications/{$applicationId}/attendance", [
                'clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60,
            ])
                ->assertOk()
                ->assertJsonPath('data.clock_in', '2026-09-20T09:00:00+09:00')
                ->assertJsonPath('data.clock_out', '2026-09-20T17:00:00+09:00')
                ->assertJsonPath('data.is_overnight', false)
                ->assertJsonPath('data.break_minutes', 60)
                ->assertJsonPath('data.worked_minutes', 420)
                ->assertJsonPath('data.wage_pay', 9100)
                ->assertJsonPath('data.transport_allowance', 500)
                ->assertJsonPath('data.total_pay', 9600);
        }
        // 応募一覧の行にも勤怠と報酬が反映される（画面はこの一覧で表示する）
        $rows = collect($this->getJson("/api/job-postings/{$postingId}/applications")->assertOk()->json('data'));
        $this->assertCount(4, $rows);
        foreach ($confirmedIds as $applicationId) {
            $this->assertSame(9600, $rows->firstWhere('id', $applicationId)['attendance']['total_pay']);
        }
        $this->assertNull($rows->firstWhere('id', $applicationIds[$sato])['attendance']);

        // ---- 10. 9 月の月次集計に 3 名分が並ぶ（M-10）。他社の勤怠とキャンセルした佐藤は入らない ----
        $report = $this->getJson('/api/reports/monthly?month=2026-09')
            ->assertOk()
            ->assertJsonPath('data.month', '2026-09')
            ->assertJsonCount(3, 'data.by_worker')
            ->assertJsonPath('data.by_location', [
                ['location_id' => $locationId, 'name' => '東京倉庫', 'shifts' => 3, 'worked_minutes' => 1260, 'total_pay' => 28800],
            ])
            ->assertJsonPath('data.total', ['shifts' => 3, 'worked_minutes' => 1260, 'total_pay' => 28800]);
        $byWorker = collect($report->json('data.by_worker'))->sortBy('worker_id')->values()->all();
        $this->assertSame([
            ['worker_id' => $suzuki, 'name' => '鈴木', 'shifts' => 1, 'worked_minutes' => 420, 'total_pay' => 9600],
            ['worker_id' => $takahashi, 'name' => '高橋', 'shifts' => 1, 'worked_minutes' => 420, 'total_pay' => 9600],
            ['worker_id' => $ito, 'name' => '伊藤', 'shifts' => 1, 'worked_minutes' => 420, 'total_pay' => 9600],
        ], $byWorker);

        // CSV（F-18）にも同じ 3 行（BOM + ヘッダ + 3 行）
        $csv = $this->get('/api/reports/monthly/csv?month=2026-09')->assertOk();
        $lines = preg_split('/\r\n|\n/', rtrim(substr($csv->streamedContent(), 3), "\r\n"));
        $this->assertCount(4, $lines);
        $this->assertStringNotContainsString('佐藤', implode("\n", $lines));
        $this->assertStringNotContainsString('他社', implode("\n", $lines));

        // ---- M-11 ダッシュボード: 自社の募集だけ。確定 3/3 で充足率 100、未処理の応募は 0 ----
        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.upcoming_postings', [[
                'id' => $postingId, 'work_date' => '2026-09-20', 'start_time' => '09:00', 'end_time' => '17:00',
                'job_title' => 'ピッキング', 'location_name' => '東京倉庫',
                'capacity' => 3, 'confirmed_count' => 3, 'fill_rate' => 100,
            ]])
            ->assertJsonPath('data.pending_applications_count', 0)
            ->assertJsonPath('data.generated_at', '2026-09-01T10:00:00+09:00');

        // 拠点は募集に使われたので削除できない
        $this->deleteJson("/api/locations/{$locationId}")
            ->assertUnprocessable()
            ->assertJsonPath('message', '募集に使われている拠点は削除できません。');
    }
}
