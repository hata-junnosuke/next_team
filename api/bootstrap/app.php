<?php

use App\Exceptions\DomainException;
use App\Http\Middleware\SetCurrentCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum の SPA 認証: SANCTUM_STATEFUL_DOMAINS から来た要求はセッション Cookie で認証する
        // https://readouble.com/laravel/13.x/ja/sanctum.html#spa-authentication
        $middleware->statefulApi();

        $middleware->alias([
            'company' => SetCurrentCompany::class,
        ]);

        // ルートモデルバインディング（SubstituteBindings）はグローバルスコープを通るので、その前に企業を決める必要がある。
        // 優先順位の既定は「認証（auth:sanctum）→ … → SubstituteBindings」なので、SubstituteBindings の直前に入れれば
        // 「認証の後・バインディングの前」になる。これがないと他社 ID が 404 にならず LogicException の 500 になる
        // https://readouble.com/laravel/13.x/ja/middleware.html#sorting-middleware
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetCurrentCompany::class);

        // 未ログイン時にリダイレクト先を作らない（JSON API 専用。ログイン画面は web 側にある）。
        // null にしておくと、AuthenticationException は 401 の JSON として返る
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // api は JSON 専用。Accept ヘッダやパスに関係なく、例外はすべて JSON で返す（HTML のエラー画面を出さない）
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // 業務ルール違反は 422 {message, errors}。field があれば errors.<field> に入れる（docs/01 4.4）
        $exceptions->render(function (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->field !== null ? [$e->field => [$e->getMessage()]] : (object) [],
            ], 422);
        });

        // 存在しない・他社のデータはどちらも同じ 404。モデル名を出さない（docs/01 6.1 のエラー形式）
        $exceptions->render(function (NotFoundHttpException $e) {
            return response()->json(['message' => '見つかりませんでした。', 'errors' => (object) []], 404);
        });

        // 試行回数の超過（throttle:login の 6 回目）。既定の英語 "Too Many Attempts." を日本語にする。
        // Retry-After などのヘッダはそのまま引き継ぐ
        $exceptions->render(function (ThrottleRequestsException $e) {
            $headers = $e->getHeaders();

            return response()->json([
                'message' => __('auth.throttle', ['seconds' => (int) ($headers['Retry-After'] ?? 60)]),
                'errors' => (object) [],
            ], 429, $headers);
        });
    })->create();
