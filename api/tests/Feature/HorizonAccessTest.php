<?php

namespace Tests\Feature;

use App\Providers\TelescopeServiceProvider;
use Illuminate\Http\Request;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Telescope\Http\Middleware\Authorize;
use Laravel\Telescope\Telescope;
use Tests\TestCase;

/**
 * /horizon と /telescope の入口（security.md: ローカル環境 かつ ログイン済みの管理者だけ）。
 * テストの環境は testing なので、ローカルの場面では app()['env'] を local に差し替える。
 * Horizon::auth() / Telescope::auth() のコールバックはリクエストのたびに環境を見るので、差し替えはリクエストの前でよい。
 * https://readouble.com/laravel/13.x/ja/horizon.html#dashboard-authorization
 */
class HorizonAccessTest extends TestCase
{
    private function actAsLocal(): void
    {
        $this->app['env'] = 'local';
    }

    public function test_未ログインでhorizonを開くと403(): void
    {
        $this->actAsLocal();

        $this->get('/horizon')->assertForbidden();
        $this->getJson('/horizon/api/stats')->assertForbidden();
    }

    public function test_ログイン済み管理者はhorizonを開ける(): void
    {
        $this->actAsLocal();

        $this->actingAsAdmin()->get('/horizon')->assertOk();
    }

    public function test_ログイン済み管理者はローカルでhorizonのapiを開ける(): void
    {
        $this->actAsLocal();
        // Horizon の API は Redis を読む（/horizon/api/stats は Redis が無いと 500）。入口の判定だけを確かめたいので、
        // Redis を読まずに済む /horizon/api/masters を選び、その読み込み先（契約インターフェース）を空を返す偽物に差し替える
        $this->mock(MasterSupervisorRepository::class, fn ($mock) => $mock->shouldReceive('all')->andReturn([]));
        $this->mock(SupervisorRepository::class, fn ($mock) => $mock->shouldReceive('all')->andReturn([]));

        $this->actingAsAdmin()->getJson('/horizon/api/masters')->assertOk();
    }

    public function test_ローカル以外では管理者でもhorizonは403(): void
    {
        $this->assertFalse($this->app->environment('local'));

        $this->actingAsAdmin()->get('/horizon')->assertForbidden();
        $this->actingAsAdmin()->getJson('/horizon/api/stats')->assertForbidden();
    }

    public function test_telescopeはローカルのログイン済み管理者だけが見られる(): void
    {
        // Telescope は local でだけ登録する（AppServiceProvider::register()）ので、テストでは入口の判定だけを確かめる
        $this->app->register(TelescopeServiceProvider::class);

        $guest = Request::create('/telescope');
        $admin = Request::create('/telescope');
        $admin->setUserResolver(fn () => $this->admin);

        $this->assertFalse(Telescope::check($admin), 'ローカル以外では管理者でも見られない');

        $this->actAsLocal();
        $this->assertFalse(Telescope::check($guest), '未ログインは見られない');
        $this->assertTrue(Telescope::check($admin), 'ローカルのログイン済み管理者は見られる');
    }

    public function test_telescopeはcookieとcsrfとパスワードを記録しない(): void
    {
        $this->app->register(TelescopeServiceProvider::class);

        foreach (['cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token', 'authorization'] as $header) {
            $this->assertContains($header, Telescope::$hiddenRequestHeaders, "ヘッダ {$header} を隠す");
        }
        foreach (['password', 'password_confirmation', '_token', 'password_hash_web', 'password_hash_sanctum'] as $parameter) {
            $this->assertContains($parameter, Telescope::$hiddenRequestParameters, "パラメータ {$parameter} を隠す");
        }
        $this->assertContains(Authorize::class, config('telescope.middleware'), '画面の入口で Telescope::check() を通す');
    }

    public function test_testing環境ではtelescopeを登録しない(): void
    {
        $this->assertFalse($this->app->providerIsLoaded(\Laravel\Telescope\TelescopeServiceProvider::class));
    }
}
