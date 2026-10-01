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
