<?php

namespace App\Http\Requests;

use App\Enums\JobPostingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 募集の一覧の絞り込み（F-06）。すべて任意。形式が不正なら黙って無視せず 422 にする。
 * 他社の拠点 ID を location_id に入れても、グローバルスコープで 0 件になるだけなので exists は付けない。
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
            // date_from がないときに after_or_equal:date_from を付けると、'date_from' という文字列を日付として読んで必ず失敗するので、あるときだけ付ける
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'location_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(JobPostingStatus::class)],
        ];
    }

    /**
     * date_format の既定の文言（「:attributeの形式が'Y-m-d'と一致しません。」）は PHP の書式記号が画面に出るので、
     * 人が読める書式（YYYY-MM-DD）で案内する。:attribute は attributes() の日本語名に置き換わる
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
            'status' => '状態',
        ];
    }
}
