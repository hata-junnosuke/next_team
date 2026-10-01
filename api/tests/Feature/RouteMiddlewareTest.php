<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * /api/* のルートを全部走査し、auth:sanctum と company の付け忘れを機械的に見つける（docs/01 6.1）。
 * 例外は署名付きURL のダウンロード（signed 付き）だけ。
 */
class RouteMiddlewareTest extends TestCase
{
    public function test_apiの全ルートにauth_sanctumとcompanyが付いている(): void
    {
        $apiRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/'));

        $this->assertNotEmpty($apiRoutes, 'api/* のルートが 1 本もない');

        foreach ($apiRoutes as $route) {
            $middleware = $route->gatherMiddleware();

            if (in_array('signed', $middleware, true)) {
                continue;
            }

            $label = implode('|', $route->methods()).' '.$route->uri();
            $this->assertContains('auth:sanctum', $middleware, "{$label} に auth:sanctum がない");
            $this->assertContains('company', $middleware, "{$label} に company がない");
        }
    }
}
