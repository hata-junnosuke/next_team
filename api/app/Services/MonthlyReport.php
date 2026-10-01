<?php

namespace App\Services;

/**
 * 月次集計の結果（F-17、docs/01 4.5・6.7）。MonthlyReportService::build() が返す値オブジェクト。
 * 金額・分はすべて attendances に保存済みの値の合計（集計時に計算し直さない）。
 */
final readonly class MonthlyReport
{
    /**
     * @param  string  $month  "YYYY-MM"
     * @param  list<array{worker_id: int, name: string, shifts: int, worked_minutes: int, total_pay: int}>  $byWorker
     * @param  list<array{location_id: int, name: string, shifts: int, worked_minutes: int, total_pay: int}>  $byLocation
     */
    public function __construct(
        public string $month,
        public array $byWorker,
        public array $byLocation,
        public int $totalShifts,
        public int $totalWorkedMinutes,
        public int $totalPay,
    ) {}
}
