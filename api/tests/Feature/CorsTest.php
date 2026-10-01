<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * config/cors.php（docs/01 5.1）。許可するのは FRONTEND_URL のオリジンだけで、Cookie つき（credentials）を通す。
 * CORS は Rails の rack-cors に相当し、Laravel では HandleCors ミドルウェアが全体に掛かっている。
 * https://readouble.com/laravel/13.x/ja/routing.html#cors
 */
class CorsTest extends TestCase
{
    private function frontendOrigin(): string
    {
        return config('cors.allowed_origins')[0];
    }

    public function test_許可オリジンはfrontend_urlの1つだけでワイルドカードではない(): void
    {
        // phpunit.xml の FRONTEND_URL と同じ値
        $this->assertSame(['http://localhost:3002'], config('cors.allowed_origins'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertSame([], config('cors.allowed_origins_patterns'));
        $this->assertTrue(config('cors.supports_credentials'));
    }

    public function test_frontend_urlからのプリフライトは許可されcredentialsも通る(): void
    {
        foreach (['/api/user', '/login', '/logout', '/sanctum/csrf-cookie'] as $path) {
            $this->call('OPTIONS', $path, server: [
                'HTTP_ORIGIN' => $this->frontendOrigin(),
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ])
                ->assertNoContent()
                ->assertHeader('Access-Control-Allow-Origin', $this->frontendOrigin())
                ->assertHeader('Access-Control-Allow-Credentials', 'true');
        }
    }

    public function test_frontend_url以外のオリジンをallow_originに返さない(): void
    {
        // 許可オリジンが 1 つのとき、Laravel の CORS は要求元に関係なく常にその 1 つ（FRONTEND_URL）を返す。
        // ブラウザは要求元と一致しない Allow-Origin を拒否するので、要求元のオリジンが返らなければ安全
        foreach (['http://evil.example', 'http://localhost:3001', 'http://localhost:8002'] as $origin) {
            $preflight = $this->call('OPTIONS', '/login', server: [
                'HTTP_ORIGIN' => $origin,
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ]);
            $this->assertNotSame($origin, $preflight->headers->get('Access-Control-Allow-Origin'));
            $this->assertContains($preflight->headers->get('Access-Control-Allow-Origin'), [null, $this->frontendOrigin()]);

            $actual = $this->actingAsAdmin()->getJson('/api/user', ['Origin' => $origin]);
            $this->assertNotSame($origin, $actual->headers->get('Access-Control-Allow-Origin'));
            $this->assertContains($actual->headers->get('Access-Control-Allow-Origin'), [null, $this->frontendOrigin()]);
        }
    }
}
