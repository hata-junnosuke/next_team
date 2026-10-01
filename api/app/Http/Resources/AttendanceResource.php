<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 勤怠の JSON（docs/01 6.6）:
 * {clock_in, clock_out, is_overnight, break_minutes, worked_minutes, wage_pay, transport_allowance, total_pay}
 * clock_in / clock_out は ISO 8601（"2026-09-20T09:00:00+09:00"）。ApplicationResource の attendance にも入る。
 *
 * @mixin Attendance
 */
class AttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'clock_in' => $this->clock_in->toIso8601String(),
            'clock_out' => $this->clock_out->toIso8601String(),
            // 退勤の日付が出勤の翌日になっていれば深夜跨ぎ（AttendanceService が翌日にした結果）
            'is_overnight' => ! $this->clock_out->isSameDay($this->clock_in),
            'break_minutes' => $this->break_minutes,
            'worked_minutes' => $this->worked_minutes,
            'wage_pay' => $this->wage_pay,
            // 保存時に「合計 = 賃金 + 交通費」で確定しているので差で出す。
            // 応募を読みに行かないので、応募一覧に埋め込んでもクエリが増えない（N+1 にならない）
            'transport_allowance' => $this->total_pay - $this->wage_pay,
            'total_pay' => $this->total_pay,
        ];
    }
}
