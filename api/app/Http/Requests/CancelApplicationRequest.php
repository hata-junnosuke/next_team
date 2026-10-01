<?php

namespace App\Http\Requests;

use App\Enums\CancelReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 確定のキャンセル（F-13）。理由区分（company 企業都合 / worker ワーカー都合）は必須。
 * 確定済みかどうかは業務ルールなので ApplicationService が見る。
 */
class CancelApplicationRequest extends FormRequest
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
            'reason' => ['required', 'string', Rule::enum(CancelReason::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'キャンセル理由'];
    }

    public function reason(): CancelReason
    {
        return CancelReason::from($this->validated('reason'));
    }
}
