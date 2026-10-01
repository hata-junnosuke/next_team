<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_正しい資格情報でログインすると200のjsonが返る(): void
    {
        $this->postJson('/login', [
            'email' => $this->admin->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertExactJson(['two_factor' => false]);

        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_誤ったパスワードは422でerrors_emailが日本語(): void
    {
        $this->postJson('/login', [
            'email' => $this->admin->email,
            'password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', '認証に失敗しました。');

        $this->assertGuest();
    }

    public function test_6回目の失敗は429(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', [
                'email' => $this->admin->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/login', [
            'email' => $this->admin->email,
            'password' => 'wrong-password',
        ])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('errors', [])
            ->assertJsonPath('message', fn (string $message) => preg_match('/^ログインの試行回数が多すぎます。\d+ 秒後にお試しください。$/u', $message) === 1);
    }

    public function test_ログアウトは204(): void
    {
        $this->actingAsAdmin()
            ->postJson('/logout')
            ->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_未ログインでapi_userを叩くと401のjsonでリダイレクトしない(): void
    {
        $this->getJson('/api/user')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        // Accept ヘッダなし（ブラウザの直接遷移など）でもリダイレクトせず 401 の JSON
        $this->get('/api/user')
            ->assertUnauthorized()
            ->assertHeaderMissing('Location')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_ログイン済みならapi_userが企業つきで返る(): void
    {
        // 他社の管理者がいても自分の情報だけが返る
        $other = Company::factory()->create();
        User::factory()->for($other)->create();

        $this->actingAsAdmin()
            ->getJson('/api/user')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $this->admin->id,
                    'name' => $this->admin->name,
                    'email' => $this->admin->email,
                    'company' => [
                        'id' => $this->company->id,
                        'name' => $this->company->name,
                    ],
                ],
            ]);
    }

    private function failLogin(string $email, string $ip = '127.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/login', ['email' => $email, 'password' => 'wrong-password']);
    }

    public function test_429はメールごとに数え別のメールは同じipでも422のまま(): void
    {
        $another = User::factory()->for($this->company)->create();

        for ($i = 0; $i < 5; $i++) {
            $this->failLogin($this->admin->email)->assertStatus(422);
        }
        $this->failLogin($this->admin->email)->assertStatus(429);

        $this->failLogin($another->email)->assertStatus(422);
    }

    public function test_429はipごとに数え同じメールでも別のipなら422のまま(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->failLogin($this->admin->email, '10.0.0.1')->assertStatus(422);
        }
        $this->failLogin($this->admin->email, '10.0.0.1')->assertStatus(429);

        $this->failLogin($this->admin->email, '10.0.0.2')->assertStatus(422);
    }

    public function test_メールの大文字小文字を変えても同じ回数として数える(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->failLogin($this->admin->email)->assertStatus(422);
        }

        $this->failLogin(strtoupper($this->admin->email))->assertStatus(429);
    }

    public function test_429の後は正しいパスワードでもログインできない(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->failLogin($this->admin->email)->assertStatus(422);
        }

        $this->postJson('/login', ['email' => $this->admin->email, 'password' => 'password'])
            ->assertStatus(429);

        $this->assertGuest();
    }

    public function test_メールとパスワードが空だと422で日本語のerrors(): void
    {
        $this->postJson('/login', ['email' => '', 'password' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password'])
            ->assertJsonPath('errors.email.0', 'メールは必須項目です。')
            ->assertJsonPath('errors.password.0', 'パスワードは必須項目です。');
    }

    public function test_csrf_cookieは204でxsrf_tokenを発行する(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN', null, false);
    }

    public function test_fortifyの登録やパスワード再設定の画面とapiは公開しない(): void
    {
        $this->postJson('/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'password'])
            ->assertNotFound();
        $this->postJson('/forgot-password', ['email' => $this->admin->email])->assertNotFound();
        $this->getJson('/register')->assertNotFound();
        // views=false なのでログイン画面（GET /login）は存在しない
        $this->get('/login')->assertStatus(405);
    }

    public function test_fortifyのパスワード確認のルートは公開しない(): void
    {
        // Fortify は features が空でも confirm-password を登録するので、ignoreRoutes() で塞いでいる
        $this->actingAsAdmin()
            ->postJson('/user/confirm-password', ['password' => 'password'])
            ->assertNotFound();
        $this->actingAsAdmin()
            ->getJson('/user/confirmed-password-status')
            ->assertNotFound();
    }

    public function test_ログインのセッションcookieでapi_userを叩けてログアウト後は401(): void
    {
        // actingAs ではなく、本番と同じく Cookie と Origin（SANCTUM_STATEFUL_DOMAINS）で認証する。
        // テストは 1 つのアプリを使い回すので、リクエストの間で認証ガードのキャッシュを捨てる（forgetGuards）
        $origin = ['Origin' => config('cors.allowed_origins')[0]];
        $cookieName = config('session.cookie');

        $session = $this->postJson('/login', ['email' => $this->admin->email, 'password' => 'password'], $origin)
            ->assertOk()
            ->getCookie($cookieName, false)
            ?->getValue();
        $this->assertNotNull($session);
        $this->app['auth']->forgetGuards();

        $this->withUnencryptedCookie($cookieName, $session)
            ->getJson('/api/user', $origin)
            ->assertOk()
            ->assertJsonPath('data.id', $this->admin->id);
        $this->app['auth']->forgetGuards();

        $this->withUnencryptedCookie($cookieName, $session)
            ->postJson('/logout', [], $origin)
            ->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->withUnencryptedCookie($cookieName, $session)
            ->getJson('/api/user', $origin)
            ->assertUnauthorized();
    }

    public function test_所属企業は一括代入で書き換えられない(): void
    {
        $other = Company::factory()->create();

        $this->admin->fill(['company_id' => $other->id, 'name' => '変更後'])->save();

        $this->assertSame($this->company->id, $this->admin->fresh()->company_id);
        $this->assertSame('変更後', $this->admin->fresh()->name);
        $this->assertTrue(Hash::check('password', $this->admin->fresh()->password));
    }
}
