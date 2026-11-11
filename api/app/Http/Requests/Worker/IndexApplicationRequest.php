<?php

namespace App\Http\Requests\Worker;

use App\Enums\ApplicationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 自分の応募の一覧（F-33。docs/01 の 6.11）。status は ApplicationStatus の値だけ（それ以外は 422）。
 * 空（?status=）は ConvertEmptyStringsToNull で null になり、絞り込まない。
 * page / per_page は Controller::perPage() が丸める（6.1 の共通）。
 */
class IndexApplicationRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(ApplicationStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => '状態',
        ];
    }

    /**
     * 検証済みの status を Enum で返す（空なら null = 絞り込まない）
     */
    public function status(): ?ApplicationStatus
    {
        return $this->enum('status', ApplicationStatus::class);
    }
}
