<?php

namespace App\Http\Requests;

use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 通知履歴の絞り込み（F-24、docs/01 6.10）。すべて任意。空なら絞り込まない。Enum にない値は黙って無視せず 422 にする。
 * https://readouble.com/laravel/13.x/ja/validation.html#rule-enum
 */
class IndexNotificationLogRequest extends FormRequest
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
            'kind' => ['nullable', Rule::enum(NotificationKind::class)],
            'status' => ['nullable', Rule::enum(NotificationStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => '種別',
            'status' => '状態',
        ];
    }

    public function kind(): ?NotificationKind
    {
        return $this->enum('kind', NotificationKind::class);
    }

    public function status(): ?NotificationStatus
    {
        return $this->enum('status', NotificationStatus::class);
    }
}
