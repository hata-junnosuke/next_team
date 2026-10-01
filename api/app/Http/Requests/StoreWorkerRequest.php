<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ワーカーの登録（F-02）。氏名とメールは必須（第3弾からメールは通知の宛先なので必須）、電話は任意。
 * is_active はここでは受け付けない（切り替えは PATCH /workers/{id}/active。登録時は常に有効）。
 * https://readouble.com/laravel/13.x/ja/validation.html#form-request-validation
 */
class StoreWorkerRequest extends FormRequest
{
    /**
     * 認可は auth:sanctum + company ミドルウェアとグローバルスコープに任せる（Policy は作らない。docs/01 4.2）
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => '氏名',
            'email' => 'メールアドレス',
            'phone' => '電話番号',
        ];
    }
}
