<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * Fortify をヘッドレス（JSON のみ）で使う。
 * config/fortify.php の features は空なので、ここでは登録・パスワード再設定などのアクションを登録しない。
 * https://readouble.com/laravel/13.x/ja/fortify.html
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fortify は features が空でも user/confirm-password などを登録する。
        // 使うのはログイン・ログアウトだけなので、Fortify のルート登録を止めて routes/web.php に 2 本だけ置く
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        // ログインの試行回数制限: メールアドレス + IP ごとに 1 分 5 回。6 回目は 429（消さないこと）
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
