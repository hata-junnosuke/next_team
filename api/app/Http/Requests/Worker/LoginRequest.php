<?php

namespace App\Http\Requests\Worker;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ワーカーのログイン（F-29。POST /api/worker/login。docs/01 の 6.11）。認証の外の公開エンドポイント。
 * 形の検証だけにする（exists や unique を書かない。企業コードやメールの有無を、照合より前に答えないため。docs/01 の 4.5）。
 * 企業コードの大文字化・メールの小文字化と照合は WorkerAuthService::login() が行う。
 * https://readouble.com/laravel/13.x/ja/validation.html#form-request-validation
 */
class LoginRequest extends FormRequest
{
    /**
     * 認証の外（企業コード・メール・パスワードの照合が本人確認）。総当たりは throttle:worker-login で防ぐ
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
            'company_code' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_code' => '企業コード',
            'email' => 'メールアドレス',
            'password' => 'パスワード',
            'device_name' => '端末名',
        ];
    }
}
