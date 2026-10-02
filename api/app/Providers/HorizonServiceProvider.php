<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon（Redis キューのワーカーと管理画面。Rails で言えば Sidekiq と Sidekiq::Web）の入口の判定。
 * 画面 /horizon は config/horizon.php の middleware = web（セッション Cookie）で、管理画面にログインしたまま開く。
 * https://readouble.com/laravel/13.x/ja/horizon.html#dashboard-authorization
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * 親の authorization() は「Gate を通る か ローカル環境」で、ローカルなら未ログインでも通してしまう。
     * security.md（ローカル環境 かつ ログイン済み管理者だけ）に合わせ、「ログイン済み かつ Gate」に上書きする。
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(function ($request): bool {
            $user = $request->user();

            return $user !== null && Gate::forUser($user)->allows('viewHorizon');
        });
    }

    /**
     * ローカル環境のログイン済み管理者（User）だけ。環境はリクエストのたびに見る。
     */
    protected function gate(): void
    {
        // ローカル専用の開発ツールなので所属企業を問わない（テナント分離の例外）
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user !== null && app()->environment('local'));
    }
}
