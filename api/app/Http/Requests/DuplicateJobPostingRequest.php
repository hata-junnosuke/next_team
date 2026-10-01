<?php

namespace App\Http\Requests;

use App\Models\JobPosting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 募集の複製（F-08）。新しい勤務日だけを受け取る。複製元はルートの {job_posting}（本文からは取らない）。
 * 要件は「別日の募集を作れる」なので、元の募集と同じ勤務日は 422 にする。
 */
class DuplicateJobPostingRequest extends FormRequest
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
        /** @var JobPosting $original ルートモデルバインディング済み（他社・存在しない ID はここに来る前に 404） */
        $original = $this->route('job_posting');

        return [
            'work_date' => ['required', 'date_format:Y-m-d', Rule::notIn([$original->work_date->toDateString()])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'work_date.not_in' => '勤務日は元の募集と別の日にしてください。',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'work_date' => '勤務日',
        ];
    }
}
