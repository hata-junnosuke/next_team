<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ワーカーの有効/無効の切り替え（F-03、PATCH /workers/{id}/active）。
 * boolean ルールは true / false / 1 / 0 / "1" / "0" を受け付ける（JSON の真偽値で送る想定）。
 * https://readouble.com/laravel/13.x/ja/validation.html#rule-boolean
 */
class ToggleWorkerActiveRequest extends FormRequest
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
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'is_active' => '有効／無効',
        ];
    }
}
