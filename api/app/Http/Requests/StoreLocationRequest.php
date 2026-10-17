<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 拠点の登録（F-01）。名称は必須、住所は任意。
 * https://readouble.com/laravel/13.x/ja/validation.html#form-request-validation
 */
class StoreLocationRequest extends FormRequest
{
    /**
     * 認可は auth:sanctum + company ミドルウェアとグローバルスコープに任せる（Policy は作らない。docs/01 のテナント分離）
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
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => '名称',
            'address' => '住所',
        ];
    }
}
