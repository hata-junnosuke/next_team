<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 月次集計と CSV の month（F-17、F-18、docs/01 の API 契約（月次集計））。
 * date_format:Y-m は「解析して書式に戻した値が入力と一致する」ことまで見るので、"2026-9" や "2026-13" は通らない。
 * https://readouble.com/laravel/13.x/ja/validation.html#rule-date-format
 */
class MonthlyReportRequest extends FormRequest
{
    /**
     * 認可は auth:sanctum + company ミドルウェアとグローバルスコープに任せる（Policy は作らない。docs/01 のテナント分離）
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'month' => ['required', 'string', 'date_format:Y-m'],
        ];
    }

    /**
     * date_format の既定の文言（「:attributeの形式が'Y-m'と一致しません。」）は PHP の書式記号が画面に出るので、
     * 人が読める書式（YYYY-MM）で案内する。:attribute は attributes() の日本語名に置き換わる
     * https://readouble.com/laravel/13.x/ja/validation.html#customizing-the-error-messages
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'month.date_format' => ':attributeは YYYY-MM の形式で指定してください。',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['month' => '対象月'];
    }
}
