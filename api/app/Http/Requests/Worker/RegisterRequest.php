<?php

namespace App\Http\Requests\Worker;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ワーカーの初回登録（F-28。POST /api/worker/register。docs/01 の 6.11）。認証の外の公開エンドポイント。
 * **unique の規則を書かない**（招待コードの確認より前に重複を答えると、認証なしでメールの登録状況が分かるため。docs/01 の 4.5）。
 * 重複・招待コードの判定は WorkerAuthService::register() が契約の順で行う。
 * 招待コードは前後の空白を TrimStrings が落とした後で 8 文字。大文字小文字はサービス（WorkerInvitation::hashCode()）がそろえる。
 * https://readouble.com/laravel/13.x/ja/validation.html#form-request-validation
 */
class RegisterRequest extends FormRequest
{
    /**
     * 認証の外（招待コードとメールの一致が本人確認の代わり）。総当たりは throttle:worker-register で防ぐ
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:8'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'メールアドレス',
            'code' => '招待コード',
            'password' => 'パスワード',
            'device_name' => '端末名',
        ];
    }
}
