<?php

namespace App\Http\Requests\Worker;

use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ワーカーの募集の検索（F-30。docs/01 の 6.11）。すべて任意。形式が不正なら黙って無視せず 422 にする。
 * 管理者の一覧（App\Http\Requests\IndexJobPostingRequest）と違い、location_id は自社の拠点であることまで確かめる（6.11 の失敗の表）。
 * Rule::exists() はグローバルスコープを通らないので company_id の条件を必ず付ける（6.4 と同じ）。
 * page / per_page は Controller::perPage() が丸める（6.1 の共通）。
 */
class IndexJobPostingRequest extends FormRequest
{
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
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            // date_from がないときに after_or_equal:date_from を付けると必ず失敗するので、あるときだけ付ける
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')->where('company_id', CurrentCompany::id()),
            ],
        ];
    }

    /**
     * date_format の既定の文言は PHP の書式記号が出るので、人が読める書式（YYYY-MM-DD）で案内する
     * https://readouble.com/laravel/13.x/ja/validation.html#customizing-the-error-messages
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_from.date_format' => ':attributeは YYYY-MM-DD の形式で指定してください。',
            'date_to.date_format' => ':attributeは YYYY-MM-DD の形式で指定してください。',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date_from' => '勤務日（から）',
            'date_to' => '勤務日（まで）',
            'location_id' => '拠点',
        ];
    }
}
