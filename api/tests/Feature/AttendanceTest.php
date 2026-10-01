<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Models\Application;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\JobPosting;
use App\Services\AttendanceService;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Context;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * F-15 勤怠入力、F-16 報酬計算（docs/01 4.6、6.6）。
 * PUT /api/applications/{id}/attendance は作成でも更新でも 200。
 * 確定済みの応募は ApplicationFactory::confirmed() で作る（担当 B の承認処理には依存しない）。
 * 既定の募集は 9:00-17:00、時給 1,300 円、交通費 500 円。
 */
class AttendanceTest extends TestCase
{
    private function confirmedApplication(array $posting = []): Application
    {
        return Application::factory()
            ->for(JobPosting::factory()->state(['work_date' => '2026-09-20', ...$posting]))
            ->confirmed()
            ->create();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function putAttendance(Application $application, array $body): TestResponse
    {
        return $this->actingAsAdmin()->putJson("/api/applications/{$application->id}/attendance", $body);
    }

    public function test_確定済みの応募に勤怠を保存できる(): void
    {
        $application = $this->confirmedApplication();

        $this->actingAsAdmin()
            ->putJson("/api/applications/{$application->id}/attendance", [
                'clock_in' => '09:00',
                'clock_out' => '17:00',
                'break_minutes' => 60,
            ])
            ->assertOk()
            ->assertExactJson(['data' => [
                'clock_in' => '2026-09-20T09:00:00+09:00',
                'clock_out' => '2026-09-20T17:00:00+09:00',
                'is_overnight' => false,
                'break_minutes' => 60,
                'worked_minutes' => 420,
                'wage_pay' => 9100,
                'transport_allowance' => 500,
                'total_pay' => 9600,
            ]]);

        $this->assertDatabaseHas('attendances', [
            'application_id' => $application->id,
            'company_id' => $this->company->id,
            'worked_minutes' => 420,
            'wage_pay' => 9100,
            'total_pay' => 9600,
        ]);
    }

    public function test_確定時の時給と交通費で計算する(): void
    {
        // 確定の後に募集の時給・交通費が変わっても、報酬は確定時にコピーした値で計算する（docs/01 4.6）
        $application = $this->confirmedApplication();
        $application->jobPosting->update(['hourly_wage' => 2000, 'transport_allowance' => 0]);

        $this->actingAsAdmin()
            ->putJson("/api/applications/{$application->id}/attendance", [
                'clock_in' => '09:00',
                'clock_out' => '17:00',
                'break_minutes' => 60,
            ])
            ->assertOk()
            ->assertJsonPath('data.wage_pay', 9100)
            ->assertJsonPath('data.transport_allowance', 500)
            ->assertJsonPath('data.total_pay', 9600);
    }

    public function test_2回目の保存は更新になる(): void
    {
        $application = $this->confirmedApplication();
        $this->actingAsAdmin()->putJson("/api/applications/{$application->id}/attendance", [
            'clock_in' => '09:00',
            'clock_out' => '17:00',
            'break_minutes' => 60,
        ])->assertOk();

        $this->putJson("/api/applications/{$application->id}/attendance", [
            'clock_in' => '10:00',
            'clock_out' => '15:00',
            'break_minutes' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('data.clock_in', '2026-09-20T10:00:00+09:00')
            ->assertJsonPath('data.worked_minutes', 300)
            ->assertJsonPath('data.wage_pay', 6500)
            ->assertJsonPath('data.total_pay', 7000);

        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseHas('attendances', ['application_id' => $application->id, 'worked_minutes' => 300, 'total_pay' => 7000]);
    }

    public function test_確定済みでない応募には保存できず422(): void
    {
        $applied = Application::factory()->create();
        $rejected = Application::factory()->rejected()->create();
        $cancelled = Application::factory()->cancelled()->create();

        foreach ([$applied, $rejected, $cancelled] as $application) {
            $this->actingAsAdmin()
                ->putJson("/api/applications/{$application->id}/attendance", [
                    'clock_in' => '09:00',
                    'clock_out' => '17:00',
                    'break_minutes' => 60,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('message', '確定済みの応募にだけ勤怠を入力できます。');
        }

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_出勤と退勤が同時刻だと422(): void
    {
        $application = $this->confirmedApplication();

        $this->actingAsAdmin()
            ->putJson("/api/applications/{$application->id}/attendance", [
                'clock_in' => '09:00',
                'clock_out' => '09:00',
                'break_minutes' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['clock_out' => '出勤と退勤が同じ時刻です。']);

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_退勤が出勤より前なら翌日扱いでis_overnightがtrue(): void
    {
        $application = $this->confirmedApplication(['start_time' => '22:00', 'end_time' => '05:00']);

        $this->actingAsAdmin()
            ->putJson("/api/applications/{$application->id}/attendance", [
                'clock_in' => '22:00',
                'clock_out' => '05:00',
                'break_minutes' => 60,
            ])
            ->assertOk()
            ->assertJsonPath('data.clock_in', '2026-09-20T22:00:00+09:00')
            ->assertJsonPath('data.clock_out', '2026-09-21T05:00:00+09:00')
            ->assertJsonPath('data.is_overnight', true)
            ->assertJsonPath('data.worked_minutes', 360)
            ->assertJsonPath('data.wage_pay', 7800)
            ->assertJsonPath('data.total_pay', 8300);
    }

    public function test_休憩が労働時間を超えると422(): void
    {
        $application = $this->confirmedApplication();

        $this->actingAsAdmin()
            ->putJson("/api/applications/{$application->id}/attendance", [
                'clock_in' => '09:00',
                'clock_out' => '10:00',
                'break_minutes' => 61,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['break_minutes' => '休憩が労働時間を超えています。']);

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_休憩が労働時間と同じなら労働0分で交通費のみ(): void
    {
        $application = $this->confirmedApplication();

        $this->actingAsAdmin()
            ->putJson("/api/applications/{$application->id}/attendance", [
                'clock_in' => '09:00',
                'clock_out' => '10:00',
                'break_minutes' => 60,
            ])
            ->assertOk()
            ->assertJsonPath('data.worked_minutes', 0)
            ->assertJsonPath('data.wage_pay', 0)
            ->assertJsonPath('data.total_pay', 500);
    }

    public function test_時刻が時と分の2桁形式でないと422(): void
    {
        $application = $this->confirmedApplication();

        $this->actingAsAdmin()
            ->putJson("/api/applications/{$application->id}/attendance", [
                'clock_in' => '9:00',
                'clock_out' => '24:00',
                'break_minutes' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['clock_in', 'clock_out', 'break_minutes']);

        $this->putJson("/api/applications/{$application->id}/attendance", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['clock_in', 'clock_out', 'break_minutes']);

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_金額は送っても無視されサーバーで計算される(): void
    {
        $application = $this->confirmedApplication();

        $this->actingAsAdmin()
            ->putJson("/api/applications/{$application->id}/attendance", [
                'clock_in' => '09:00',
                'clock_out' => '17:00',
                'break_minutes' => 60,
                'worked_minutes' => 9999,
                'wage_pay' => 999999,
                'total_pay' => 999999,
            ])
            ->assertOk()
            ->assertJsonPath('data.total_pay', 9600);

        $this->assertDatabaseHas('attendances', ['application_id' => $application->id, 'total_pay' => 9600]);
    }

    public function test_他社の応募には保存できず404(): void
    {
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => $this->confirmedApplication());

        // 本番のリクエストは CurrentCompany が空の状態で始まる。setUp で入れた値に助けられないよう消してから叩く
        Context::forget('company_id');

        $this->actingAsAdmin()
            ->putJson("/api/applications/{$theirs->id}/attendance", [
                'clock_in' => '09:00',
                'clock_out' => '17:00',
                'break_minutes' => 60,
            ])
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_他社の応募に勤怠があっても保存は404で他社の値は変わらない(): void
    {
        $other = Company::factory()->create();
        $theirApplication = CurrentCompany::runAs($other->id, fn () => $this->confirmedApplication());
        $theirs = CurrentCompany::runAs($other->id, fn () => Attendance::factory()->create(['application_id' => $theirApplication->id]));

        // 本番のリクエストは CurrentCompany が空の状態で始まる。setUp で入れた値に助けられないよう消してから叩く
        Context::forget('company_id');

        $this->putAttendance($theirApplication, [
            'clock_in' => '10:00',
            'clock_out' => '11:00',
            'break_minutes' => 0,
        ])
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);

        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseHas('attendances', [
            'id' => $theirs->id,
            'company_id' => $other->id,
            'clock_in' => $theirs->clock_in->format('Y-m-d H:i:s'),
            'clock_out' => $theirs->clock_out->format('Y-m-d H:i:s'),
            'worked_minutes' => 420,
            'total_pay' => 9600,
        ]);
    }

    public function test_確定時の時給か交通費が空なら例外で保存しない(): void
    {
        $service = app(AttendanceService::class);

        foreach (['confirmed_hourly_wage', 'confirmed_transport_allowance'] as $column) {
            // テストの前提づくり: transitionTo() を通らずに確定済みになった不整合なデータ
            $application = Application::factory()->confirmed()->create([$column => null]);

            try {
                $service->save($application, '09:00', '17:00', 60);
                $this->fail("{$column} が null なのに LogicException になりませんでした。");
            } catch (LogicException $e) {
                $this->assertStringContainsString('確定時の時給または交通費がありません', $e->getMessage());
            }
        }

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_レスポンスの形がdocs01の6章6どおり(): void
    {
        $application = $this->confirmedApplication();

        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60])
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'clock_in', 'clock_out', 'is_overnight', 'break_minutes',
                'worked_minutes', 'wage_pay', 'transport_allowance', 'total_pay',
            ]]);
    }

    public function test_未ログインだと401(): void
    {
        $application = $this->confirmedApplication();

        $this->putJson("/api/applications/{$application->id}/attendance", [
            'clock_in' => '09:00',
            'clock_out' => '17:00',
            'break_minutes' => 60,
        ])->assertUnauthorized();

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_存在しない応募は404(): void
    {
        $this->actingAsAdmin()
            ->putJson('/api/applications/999999/attendance', ['clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60])
            ->assertNotFound();
    }

    public function test_応募中の応募は422(): void
    {
        $application = Application::factory()->create();

        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60])
            ->assertUnprocessable()
            ->assertJsonPath('message', '確定済みの応募にだけ勤怠を入力できます。');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_却下の応募は422(): void
    {
        $application = Application::factory()->rejected()->create();

        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60])
            ->assertUnprocessable()
            ->assertJsonPath('message', '確定済みの応募にだけ勤怠を入力できます。');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_キャンセル済みの応募は企業都合でもワーカー都合でも422(): void
    {
        foreach ([CancelReason::Company, CancelReason::Worker] as $reason) {
            $application = Application::factory()->cancelled($reason)->create();

            $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60])
                ->assertUnprocessable()
                ->assertJsonPath('message', '確定済みの応募にだけ勤怠を入力できます。');
        }

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_勤怠入力後にキャンセルされた応募は更新できず既存の勤怠も変わらない(): void
    {
        $application = $this->confirmedApplication();
        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60])->assertOk();

        // テストの前提づくり（キャンセル処理そのものは担当 B の ApplicationService）。状態は transitionTo() だけで変える
        $application->transitionTo(ApplicationStatus::Cancelled, [
            'cancel_reason' => CancelReason::Worker,
            'cancelled_at' => now(),
        ]);

        $this->putAttendance($application, ['clock_in' => '10:00', 'clock_out' => '12:00', 'break_minutes' => 0])
            ->assertUnprocessable()
            ->assertJsonPath('message', '確定済みの応募にだけ勤怠を入力できます。');

        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseHas('attendances', ['application_id' => $application->id, 'worked_minutes' => 420, 'total_pay' => 9600]);
    }

    public function test_23時59分から0時0分は翌日扱いで1分(): void
    {
        $application = $this->confirmedApplication();

        $this->putAttendance($application, ['clock_in' => '23:59', 'clock_out' => '00:00', 'break_minutes' => 0])
            ->assertOk()
            ->assertJsonPath('data.clock_in', '2026-09-20T23:59:00+09:00')
            ->assertJsonPath('data.clock_out', '2026-09-21T00:00:00+09:00')
            ->assertJsonPath('data.is_overnight', true)
            ->assertJsonPath('data.worked_minutes', 1)
            // 1 分 × 1,300 円 / 60 = 21.66… → 22 円
            ->assertJsonPath('data.wage_pay', 22)
            ->assertJsonPath('data.total_pay', 522);
    }

    public function test_0時0分から23時59分は同じ日で1439分(): void
    {
        $application = $this->confirmedApplication();

        $this->putAttendance($application, ['clock_in' => '00:00', 'clock_out' => '23:59', 'break_minutes' => 0])
            ->assertOk()
            ->assertJsonPath('data.clock_in', '2026-09-20T00:00:00+09:00')
            ->assertJsonPath('data.clock_out', '2026-09-20T23:59:00+09:00')
            ->assertJsonPath('data.is_overnight', false)
            ->assertJsonPath('data.worked_minutes', 1439);
    }

    public function test_退勤が出勤の1分前なら翌日扱いで1439分(): void
    {
        $application = $this->confirmedApplication();

        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '08:59', 'break_minutes' => 0])
            ->assertOk()
            ->assertJsonPath('data.clock_out', '2026-09-21T08:59:00+09:00')
            ->assertJsonPath('data.is_overnight', true)
            ->assertJsonPath('data.worked_minutes', 1439);
    }

    public function test_退勤が出勤の1分後なら同じ日で1分(): void
    {
        $application = $this->confirmedApplication();

        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '09:01', 'break_minutes' => 0])
            ->assertOk()
            ->assertJsonPath('data.clock_out', '2026-09-20T09:01:00+09:00')
            ->assertJsonPath('data.is_overnight', false)
            ->assertJsonPath('data.worked_minutes', 1);
    }

    public function test_0時0分同士の同時刻は翌日扱いにせず422(): void
    {
        // 「退勤 ≤ 出勤なら翌日」のうち等号の場合は 24 時間勤務ではなくエラー（docs/01 4.6）
        $application = $this->confirmedApplication();

        $this->putAttendance($application, ['clock_in' => '00:00', 'clock_out' => '00:00', 'break_minutes' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['clock_out' => '出勤と退勤が同じ時刻です。']);

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_月末の深夜跨ぎは退勤が翌月1日になる(): void
    {
        $application = $this->confirmedApplication(['work_date' => '2026-09-30']);

        $this->putAttendance($application, ['clock_in' => '22:00', 'clock_out' => '05:00', 'break_minutes' => 0])
            ->assertOk()
            ->assertJsonPath('data.clock_in', '2026-09-30T22:00:00+09:00')
            ->assertJsonPath('data.clock_out', '2026-10-01T05:00:00+09:00')
            ->assertJsonPath('data.is_overnight', true)
            ->assertJsonPath('data.worked_minutes', 420);
    }

    public function test_深夜跨ぎで休憩が労働時間と同じなら0分(): void
    {
        $application = $this->confirmedApplication();

        $this->putAttendance($application, ['clock_in' => '23:00', 'clock_out' => '01:00', 'break_minutes' => 120])
            ->assertOk()
            ->assertJsonPath('data.worked_minutes', 0)
            ->assertJsonPath('data.total_pay', 500);
    }

    public function test_深夜跨ぎで休憩が労働時間を1分超えると422(): void
    {
        // 翌日扱いの後の拘束時間（120 分）で比べる。翌日扱い前の負の差で比べていないこと
        $application = $this->confirmedApplication();

        $this->putAttendance($application, ['clock_in' => '23:00', 'clock_out' => '01:00', 'break_minutes' => 121])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['break_minutes' => '休憩が労働時間を超えています。']);

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_休憩0分は保存できる(): void
    {
        $application = $this->confirmedApplication();

        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 0])
            ->assertOk()
            ->assertJsonPath('data.break_minutes', 0)
            ->assertJsonPath('data.worked_minutes', 480)
            ->assertJsonPath('data.wage_pay', 10400);
    }

    public function test_休憩1440分は形式として通り労働時間超過で422(): void
    {
        $application = $this->confirmedApplication();

        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '08:59', 'break_minutes' => 1440])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['break_minutes' => '休憩が労働時間を超えています。']);
    }

    public function test_休憩1441分は形式エラーで422(): void
    {
        $application = $this->confirmedApplication();

        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '08:59', 'break_minutes' => 1441])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['break_minutes'])
            ->assertJsonMissingValidationErrors(['clock_in', 'clock_out']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInputs(): array
    {
        return [
            '出勤が秒つき' => [['clock_in' => '09:00:00'], 'clock_in'],
            '出勤の分が60' => [['clock_in' => '09:60'], 'clock_in'],
            '退勤が文字列' => [['clock_out' => 'abc'], 'clock_out'],
            '退勤が数値' => [['clock_out' => 1700], 'clock_out'],
            '休憩が小数' => [['break_minutes' => 1.5], 'break_minutes'],
            '休憩が文字列' => [['break_minutes' => 'abc'], 'break_minutes'],
            '休憩が空' => [['break_minutes' => null], 'break_minutes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidInputs')]
    public function test_入力の形式エラーは該当キーだけ422(array $override, string $key): void
    {
        $application = $this->confirmedApplication();

        $this->putAttendance($application, [...['clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60], ...$override])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => [$key]])
            ->assertJsonCount(1, 'errors');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_形式エラーのメッセージは日本語の項目名を使う(): void
    {
        $application = $this->confirmedApplication();

        $response = $this->putAttendance($application, [])->assertUnprocessable();

        $this->assertStringContainsString('出勤', $response->json('errors.clock_in.0'));
        $this->assertStringContainsString('退勤', $response->json('errors.clock_out.0'));
        $this->assertStringContainsString('休憩', $response->json('errors.break_minutes.0'));
    }

    public function test_勤怠保存後に募集の時給を変えても保存済みと再計算の金額は確定時の値(): void
    {
        // 確定時の値（confirmed_*）だけを見ること。応募の確定後、勤怠保存の前後どちらで募集を変えても影響しない
        $application = $this->confirmedApplication();
        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60])->assertOk();

        $application->jobPosting->update(['hourly_wage' => 5000, 'transport_allowance' => 3000]);

        // 保存済みの値は変わらない
        $this->assertDatabaseHas('attendances', ['application_id' => $application->id, 'wage_pay' => 9100, 'total_pay' => 9600]);

        // 更新で再計算しても確定時の値で計算する
        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '18:00', 'break_minutes' => 60])
            ->assertOk()
            ->assertJsonPath('data.worked_minutes', 480)
            ->assertJsonPath('data.wage_pay', 10400)
            ->assertJsonPath('data.transport_allowance', 500)
            ->assertJsonPath('data.total_pay', 10900);
    }

    public function test_深夜跨ぎから日中に更新するとis_overnightがfalseに戻り再計算される(): void
    {
        $application = $this->confirmedApplication();
        $this->putAttendance($application, ['clock_in' => '22:00', 'clock_out' => '05:00', 'break_minutes' => 60])
            ->assertOk()
            ->assertJsonPath('data.is_overnight', true);

        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '12:00', 'break_minutes' => 0])
            ->assertOk()
            ->assertJsonPath('data.clock_out', '2026-09-20T12:00:00+09:00')
            ->assertJsonPath('data.is_overnight', false)
            ->assertJsonPath('data.worked_minutes', 180)
            ->assertJsonPath('data.wage_pay', 3900)
            ->assertJsonPath('data.total_pay', 4400);

        $attendance = Attendance::query()->where('application_id', $application->id)->sole();
        $this->assertSame('2026-09-20 12:00', $attendance->clock_out->format('Y-m-d H:i'));
        $this->assertSame(180, $attendance->worked_minutes);
    }

    public function test_更新で休憩超過なら422で既存の勤怠は変わらない(): void
    {
        $application = $this->confirmedApplication();
        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '17:00', 'break_minutes' => 60])->assertOk();

        $this->putAttendance($application, ['clock_in' => '09:00', 'clock_out' => '10:00', 'break_minutes' => 90])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['break_minutes']);

        $this->assertDatabaseHas('attendances', ['application_id' => $application->id, 'worked_minutes' => 420, 'total_pay' => 9600]);
    }
}
