<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 月次集計と CSV の month（F-17、F-18、docs/01 6.7）。
 * date_format:Y-m は「解析して書式に戻した値が入力と一致する」ことまで見るので、"2026-9" や "2026-13" は通らない。
 * https://readouble.com/laravel/13.x/ja/validation.html#rule-date-format
 */
class MonthlyReportRequest extends FormRequest
{
    /**
     * 認可は auth:sanctum + company ミドルウェアとグローバルスコープに任せる（Policy は作らない。docs/01 4.2）
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
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['month' => '対象月'];
    }
}
