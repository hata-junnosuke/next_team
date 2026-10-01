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
     * date_format の既定の文言（「:attributeの形式が'Y-m-d'と一致しません。」）は PHP の書式記号が画面に出るので、
     * 人が読める書式（YYYY-MM-DD）で案内する。:attribute は attributes() の日本語名に置き換わる
     * https://readouble.com/laravel/13.x/ja/validation.html#customizing-the-error-messages
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'work_date.date_format' => ':attributeは YYYY-MM-DD の形式で入力してください。',
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
