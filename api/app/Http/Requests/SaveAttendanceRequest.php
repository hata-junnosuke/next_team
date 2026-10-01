<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 勤怠の保存（F-15、docs/01 6.6）。形式だけを検証する。
 * 「確定済みの応募だけ」「同時刻はエラー」「休憩が労働時間を超えない」は時刻の組み立て（深夜跨ぎ）と
 * 切り離せないので AttendanceService の 1 箇所で判定する（docs/01 4.6）。
 * 金額（worked_minutes / wage_pay / total_pay）は受け取らない。サーバーで計算する。
 * https://readouble.com/laravel/13.x/ja/validation.html#rule-date-format
 */
class SaveAttendanceRequest extends FormRequest
{
    /**
     * 認可は auth:sanctum + company ミドルウェアとグローバルスコープに任せる（Policy は作らない。docs/01 4.2）
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * date_format:H:i は「解析して書式に戻した値が入力と一致する」ことまで見るので、
     * "9:00"（先頭ゼロなし）や "24:00" は通らない。HH:MM の 00:00〜23:59 だけになる
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clock_in' => ['required', 'string', 'date_format:H:i'],
            'clock_out' => ['required', 'string', 'date_format:H:i'],
            // 1 日（1,440 分）を超える休憩はありえない。上限は「労働時間を超えない」をサービスで見る
            'break_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'clock_in' => '出勤',
            'clock_out' => '退勤',
            'break_minutes' => '休憩',
        ];
    }
}
