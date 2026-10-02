<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

/**
 * Telescope（開発用のデバッグ画面。リクエスト・クエリ・ジョブ・メールを記録する）の設定と入口の判定。
 * 開発用なので bootstrap/providers.php には書かず、AppServiceProvider::register() がローカル環境だけで登録する。
 */
class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        // ローカルでしか登録しないので Telescope::filter() は書かず全件記録する
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        // ローカルでも Cookie と CSRF トークンは記録しない（security.md: ログにパスワードや Cookie を入れない）。
        // パスワード（password / password_confirmation）と authorization ヘッダは Telescope が既定で隠す。
        // password_hash_web / password_hash_sanctum は Sanctum の AuthenticateSession がセッションに入れるパスワードのハッシュ。
        // Telescope はセッションの中身も同じ一覧で隠すので、ここに足す。
        Telescope::hideRequestParameters([
            '_token',
            'password_hash_web',
            'password_hash_sanctum',
        ]);

        // RequestWatcher は応答ヘッダも同じ一覧で隠す。set-cookie を入れないと laravel_session と XSRF-TOKEN の値が残る
        Telescope::hideRequestHeaders([
            'cookie',
            'set-cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * 親の authorization() は「ローカル環境 または Gate」で、ローカルなら未ログインでも通してしまう。
     * security.md（ローカル環境 かつ ログイン済み管理者だけ）に合わせ、「ログイン済み かつ Gate」に上書きする。
     * https://readouble.com/laravel/13.x/ja/telescope.html#dashboard-authorization
     */
    protected function authorization(): void
    {
        $this->gate();

        Telescope::auth(function ($request): bool {
            $user = $request->user();

            return $user !== null && Gate::forUser($user)->allows('viewTelescope');
        });
    }

    /**
     * ローカル環境のログイン済み管理者（User）だけ。環境はリクエストのたびに見る。
     */
    protected function gate(): void
    {
        // ローカル専用の開発ツールなので所属企業を問わない（テナント分離の例外）
        Gate::define('viewTelescope', fn (?User $user = null): bool => $user !== null && app()->environment('local'));
    }
}
