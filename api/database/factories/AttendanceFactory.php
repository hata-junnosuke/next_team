<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * 確定前の応募を渡すと LogicException。
 * 既定は「確定済みの応募に、募集の勤務日・開始〜終了どおり、休憩 60 分」の勤怠。
 * 金額の列は確定時の時給・交通費から入れる（既定の募集なら 420 分 → 9,100 円 + 500 円 = 9,600 円）。
 *
 * ここの計算はテストの前提を作るためのもの。本番の計算の正は PayrollCalculator（担当 C）で、
 * 深夜跨ぎ（退勤 ≤ 出勤なら翌日）の判定も AttendanceService の 1 箇所にある。
 * 時刻を変えたいときは create(['clock_in' => ..., 'clock_out' => ..., 'worked_minutes' => ..., ...]) で全部渡す。
 *
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory()->confirmed(),
            'clock_in' => fn (array $attributes) => self::shift($attributes['application_id'])['clock_in'],
            'clock_out' => fn (array $attributes) => self::shift($attributes['application_id'])['clock_out'],
            'break_minutes' => 60,
            'worked_minutes' => fn (array $attributes) => self::workedMinutes($attributes),
            'wage_pay' => fn (array $attributes) => self::wagePay($attributes),
            'total_pay' => fn (array $attributes) => self::wagePay($attributes)
                + (int) self::application($attributes['application_id'])->confirmed_transport_allowance,
        ];
    }

    /**
     * @return array{clock_in: CarbonImmutable, clock_out: CarbonImmutable}
     */
    private static function shift(int $applicationId): array
    {
        $posting = self::application($applicationId)->jobPosting;
        $date = $posting->work_date->toDateString();
        $clockIn = CarbonImmutable::parse("{$date} {$posting->start_time}");
        $clockOut = CarbonImmutable::parse("{$date} {$posting->end_time}");

        return [
            'clock_in' => $clockIn,
            'clock_out' => $clockOut->lessThanOrEqualTo($clockIn) ? $clockOut->addDay() : $clockOut,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function workedMinutes(array $attributes): int
    {
        $clockIn = CarbonImmutable::parse($attributes['clock_in']);
        $clockOut = CarbonImmutable::parse($attributes['clock_out']);

        return max((int) $clockIn->diffInMinutes($clockOut) - (int) $attributes['break_minutes'], 0);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function wagePay(array $attributes): int
    {
        $wage = (int) self::application($attributes['application_id'])->confirmed_hourly_wage;

        // 円未満切り上げ（docs/01 4.6 と同じ式）
        return intdiv(self::workedMinutes($attributes) * $wage + 59, 60);
    }

    /**
     * 勤怠は確定済みの応募にだけ付く（F-15）。確定前の応募を渡したら、テストの前提の誤りとして止める
     */
    private static function application(int $applicationId): Application
    {
        $application = Application::query()->with('jobPosting')->findOrFail($applicationId);

        if ($application->status !== ApplicationStatus::Confirmed) {
            throw new LogicException("勤怠のファクトリには確定済みの応募を渡してください（応募 {$applicationId} は {$application->status->value}）。");
        }

        return $application;
    }
}
