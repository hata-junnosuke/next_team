<?php

namespace Tests\Feature;

use App\Exceptions\DomainException;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Worker;
use App\Models\WorkerInvitation;
use App\Providers\TelescopeServiceProvider;
use App\Services\WorkerAuthService;
use App\Support\CurrentCompany;
use Illuminate\Hashing\HashManager;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Laravel\Telescope\Telescope;
use Mockery;
use RuntimeException;
use Tests\Concerns\ActsAsWorker;
use Tests\TestCase;

/**
 * ワーカーの認証（docs/01 の 5.3、6.11）。守り（「入れないことの確認」）は本物のトークンを withToken() で付けて書く。
 * Sanctum::actingAs() は guard の provider の確認（hasValidProvider）とトークンの期限を通らないので、守りを試せない（5.3）。
 * 本番のリクエストは企業が空の状態で始まるので、叩く前に Context の企業を消す（setUp の値に助けられないように）。
 */
class WorkerAuthTest extends TestCase
{
    use ActsAsWorker;

    private const DISABLED_MESSAGE = 'このアカウントは現在利用できません。管理者にお問い合わせください。';

    private const INVALID_CODE_MESSAGE = '招待コードが正しくないか、有効期限が切れています。';

    private const EMAIL_TAKEN_MESSAGE = 'このメールアドレスは、この企業の別のワーカーのログインに使われています。';

    private const INVALID_CREDENTIALS_MESSAGE = '企業コード・メールアドレス・パスワードのいずれかが正しくありません。';

    /** 招待コードの文字（紛らわしい文字を除いた英大文字と数字）だけで作った、テストで使う招待コード */
    private const CODE = 'ABCD2345';

    /** expectOnePasswordCheck() が包む本物のハッシャー */
    private ?HashManager $realHasher = null;

    // ---- 初回登録（F-28。POST /api/worker/register。docs/01 の 4.5・5.3・6.11） ----

    public function test_招待コードで初回登録すると201でトークンとワーカーが返り同じコードはもう使えない(): void
    {
        // DB の日時は秒までなので、秒の頭で止める（マイクロ秒で比べがずれないように）
        $this->freezeSecond();
        $this->company->forceFill(['name' => '株式会社サンプル', 'code' => 'SMPK23'])->save();
        $worker = $this->invitedWorker('taro@example.com');
        $invitation = $worker->invitations()->sole();
        Context::forget('company_id');

        $response = $this->postJson('/api/worker/register', $this->registerPayload())
            ->assertCreated()
            ->assertJsonPath('data.worker', [
                'id' => $worker->id,
                'name' => '山田 太郎',
                'email' => 'taro@example.com',
                'company' => ['id' => $this->company->id, 'name' => '株式会社サンプル', 'code' => 'SMPK23'],
            ]);
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertEqualsCanonicalizing(['token', 'worker'], array_keys($response->json('data')));

        // 招待は使用済みになり、暗号化したコードも消える
        $invitation = CurrentCompany::runAs($this->company->id, fn () => $invitation->fresh());
        $this->assertNotNull($invitation->used_at);
        $this->assertNull($invitation->getRawOriginal('code'));
        $this->assertNull($invitation->revoked_at);

        // ワーカーにログイン用の列が入る
        $worker = CurrentCompany::runAs($this->company->id, fn () => $worker->fresh());
        $this->assertSame('taro@example.com', $worker->login_email);
        $this->assertTrue(now()->equalTo($worker->registered_at));
        $this->assertTrue(Hash::check('secret-pass', $worker->password));

        // トークンは端末名・能力 ['worker'] ちょうど・設定の日数後に切れる（* や期限なしを出さない）
        $token = $worker->tokens()->sole();
        $this->assertSame('iPhone 16', $token->name);
        $this->assertSame(['worker'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue(now()->addDays((int) config('myteam.worker_token_days'))->equalTo($token->expires_at));
        $this->assertSame($token->id, (int) explode('|', $response->json('data.token'))[0]);

        // 同じコードはもう使えない（同じメール・同じコードで別のパスワードでも、パスワードもトークンも変わらない）
        $this->postJson('/api/worker/register', $this->registerPayload([
            'password' => 'other-pass',
            'password_confirmation' => 'other-pass',
            'device_name' => 'iPad',
        ]))
            ->assertUnprocessable()
            ->assertExactJson(['message' => self::INVALID_CODE_MESSAGE, 'errors' => ['code' => [self::INVALID_CODE_MESSAGE]]]);
        $this->assertSame(1, $worker->tokens()->count());
        $this->assertTrue(Hash::check('secret-pass', CurrentCompany::runAs($this->company->id, fn () => $worker->fresh())->password));
    }

    public function test_ワーカーのトークンで自分の情報を取得できる(): void
    {
        $worker = $this->registeredWorker(['name' => '山田 太郎', 'email' => 'Yamada@Example.com']);
        $token = $this->workerToken($worker);
        Context::forget('company_id');

        $this->withToken($token)
            ->getJson('/api/worker/me')
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $worker->id,
                'name' => '山田 太郎',
                'email' => 'yamada@example.com',
                'company' => [
                    'id' => $this->company->id,
                    'name' => $this->company->name,
                    'code' => $this->company->code,
                ],
            ]]);
    }

    public function test_ワーカーのトークンで管理者のapi_userを叩くと401(): void
    {
        $token = $this->workerToken($this->registeredWorker());
        Context::forget('company_id');

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();

        // company ミドルウェアより手前の guard sanctum（provider: users）の時点で拒否されている（docs/01 の 13 章の 7）
        $this->assertGuest('sanctum');
    }

    public function test_ワーカーのトークンで管理者の募集一覧を叩くと401(): void
    {
        $token = $this->workerToken($this->registeredWorker());
        Context::forget('company_id');

        $this->withToken($token)->getJson('/api/job-postings')->assertUnauthorized();
    }

    public function test_管理者のguardを通ったワーカーでもcompanyミドルウェアが401にする(): void
    {
        // guard の provider の確認を飛ばしてワーカーを guard sanctum に入れる（provider の設定が外れた場合の 2 重の守りを試す）
        Sanctum::actingAs($this->registeredWorker(), ['*'], 'sanctum');
        Context::forget('company_id');

        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_管理者のcookieでワーカーの自分の情報を叩くと401(): void
    {
        Context::forget('company_id');

        // stateful なリクエストでは Sanctum の guard worker も web ガードの管理者（User）を返す。worker ミドルウェアの型の確認が止める（13 章の 9）
        $this->actingAsAdmin()->getJson('/api/worker/me')->assertUnauthorized();
    }

    public function test_無効にされたワーカーのトークンは401で利用できない旨の文言(): void
    {
        $worker = $this->registeredWorker();
        $token = $this->workerToken($worker);
        $worker->update(['is_active' => false]);
        Context::forget('company_id');

        $this->withToken($token)
            ->getJson('/api/worker/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => self::DISABLED_MESSAGE, 'errors' => []]);
    }

    public function test_有効に戻せば同じトークンでまた使える(): void
    {
        $worker = $this->registeredWorker();
        $token = $this->workerToken($worker);
        $worker->update(['is_active' => false]);
        Context::forget('company_id');

        $this->withToken($token)->getJson('/api/worker/me')->assertUnauthorized();

        CurrentCompany::runAs($this->company->id, fn () => $worker->update(['is_active' => true]));
        $this->forgetAuthenticatedUsers();

        $this->withToken($token)->getJson('/api/worker/me')->assertOk()->assertJsonPath('data.id', $worker->id);
    }

    public function test_期限切れのトークンは401(): void
    {
        $token = $this->workerToken($this->registeredWorker(), expiresAt: now()->subMinute());
        Context::forget('company_id');

        $this->withToken($token)->getJson('/api/worker/me')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_ワーカーの権限を持たないトークンは401(): void
    {
        $token = $this->workerToken($this->registeredWorker(), abilities: ['something-else']);
        Context::forget('company_id');

        $this->withToken($token)->getJson('/api/worker/me')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_未登録のワーカーのトークンは401(): void
    {
        // 本来トークンは初回登録の後にしか出ないが、登録を取り消した場合などに備えて registered_at でも止める（5.3）
        $token = $this->workerToken(Worker::factory()->create());
        Context::forget('company_id');

        $this->withToken($token)->getJson('/api/worker/me')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_トークンなしでワーカーのapiを叩くと401(): void
    {
        Context::forget('company_id');

        $this->getJson('/api/worker/me')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->postJson('/api/worker/logout')->assertUnauthorized();
        $this->withToken('1|not-a-real-token')->getJson('/api/worker/me')->assertUnauthorized();
    }

    public function test_ログアウトで今のトークンだけ消えほかの端末のトークンは使える(): void
    {
        $worker = $this->registeredWorker();
        $phone = $this->workerToken($worker, deviceName: 'iPhone 16');
        $tablet = $this->workerToken($worker, deviceName: 'iPad');
        Context::forget('company_id');

        $this->withToken($phone)->postJson('/api/worker/logout')->assertNoContent();
        $this->forgetAuthenticatedUsers();

        $this->withToken($phone)->getJson('/api/worker/me')->assertUnauthorized();
        $this->forgetAuthenticatedUsers();

        $this->withToken($tablet)->getJson('/api/worker/me')->assertOk()->assertJsonPath('data.id', $worker->id);
        $this->assertSame(['iPad'], $worker->tokens()->pluck('name')->all());
    }

    public function test_企業が決まる前でもトークンから他社のワーカーを読めてその企業の情報が返る(): void
    {
        // docs/01 の 13 章の 8。PersonalAccessToken::tokenable() の withoutGlobalScope('company') を外すと、
        // 企業が空のリクエストでは CurrentCompany::id() の LogicException で 500 になる（tester がトークンのモデルを差し替えて確認済み）。
        // setUp の企業（$this->company）ではないワーカーにして、setUp の値や同じ企業の偶然に助けられないようにする
        $other = Company::factory()->create();
        $worker = CurrentCompany::runAs($other->id, fn () => $this->registeredWorker());
        $token = $this->workerToken($worker);
        Context::forget('company_id');

        $this->withToken($token)
            ->getJson('/api/worker/me')
            ->assertOk()
            ->assertJsonPath('data.id', $worker->id)
            ->assertJsonPath('data.company.id', $other->id)
            ->assertJsonPath('data.company.code', $other->code);
    }

    public function test_workerミドルウェアがワーカーの企業を決めルートモデルバインディングで他社の募集は404(): void
    {
        // worker ミドルウェアが CurrentCompany をワーカーの企業で埋め、SubstituteBindings より前に動くこと（docs/01 の 4.2・5.3）。
        // 企業を埋めなければ LogicException の 500、setUp の企業が残っていれば自社と他社が逆になる
        $other = Company::factory()->create();
        [$worker, $ownPosting] = CurrentCompany::runAs($other->id, fn () => [
            $this->registeredWorker(),
            JobPosting::factory()->create(),
        ]);
        $setUpCompanyPosting = JobPosting::factory()->create();
        $token = $this->workerToken($worker);
        Context::forget('company_id');

        $this->withToken($token)
            ->getJson("/api/worker/job-postings/{$setUpCompanyPosting->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);
        $this->forgetAuthenticatedUsers();

        // 自社の募集中・今日以降の募集はバインディングを通り、詳細が返る
        $this->withToken($token)
            ->getJson("/api/worker/job-postings/{$ownPosting->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $ownPosting->id);
    }

    public function test_ワーカーのトークンでは管理者のapiのどのルートにも入れない(): void
    {
        // 5.3 の「入れないことの確認」の 1 行目を、/api/user と募集一覧だけでなく管理者の全ルートで確かめる。
        // ルート一覧から取るので、ルートを足しても漏れない。署名付きURL のダウンロード（signed。認証の外）は除く
        $token = $this->workerToken($this->registeredWorker());
        Context::forget('company_id');

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/'))
            ->reject(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/worker/'))
            ->reject(fn (RoutingRoute $route) => in_array('signed', $route->gatherMiddleware(), true));
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $method = collect($route->methods())->reject(fn (string $m) => $m === 'HEAD')->first();
            // パラメータは 1 で埋める（認証はバインディングより前なので、ID の有無に関係なく 401 になるべき）
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());

            $this->withToken($token)->json($method, $uri)->assertUnauthorized();
            $this->forgetAuthenticatedUsers();
        }
    }

    public function test_管理者のcookieではワーカーのapiのどのルートにも入れない(): void
    {
        // 5.3 の「入れないことの確認」の 2 行目（register・login 以外の /api/worker/*）。13 章の 9
        $posting = JobPosting::factory()->create();
        Context::forget('company_id');

        foreach (self::protectedWorkerRoutes() as [$method, $uri]) {
            $this->actingAsAdmin()
                ->json($method, str_replace('{job_posting}', (string) $posting->id, $uri))
                ->assertUnauthorized();
        }
    }

    public function test_トークンなしではワーカーのapiのどのルートにも入れない(): void
    {
        $posting = JobPosting::factory()->create();
        Context::forget('company_id');

        foreach (self::protectedWorkerRoutes() as [$method, $uri]) {
            $this->json($method, str_replace('{job_posting}', (string) $posting->id, $uri))
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Unauthenticated.']);
        }
    }

    public function test_無効にされたワーカーのトークンはワーカーのapiのどのルートにも入れない(): void
    {
        // 5.3 の「入れないことの確認」の 3 行目（/api/worker/me「など」）。応募（POST）でも 401 で止まり、応募は作られない
        $worker = $this->registeredWorker();
        $token = $this->workerToken($worker);
        $worker->update(['is_active' => false]);
        $posting = JobPosting::factory()->create();
        Context::forget('company_id');

        foreach (self::protectedWorkerRoutes() as [$method, $uri]) {
            $this->withToken($token)
                ->json($method, str_replace('{job_posting}', (string) $posting->id, $uri))
                ->assertUnauthorized()
                ->assertExactJson(['message' => self::DISABLED_MESSAGE, 'errors' => []]);
            $this->forgetAuthenticatedUsers();
        }

        // 401 で止まりコントローラまで届かない: 応募は作られず、ログアウトでもトークンは消えない
        $this->assertDatabaseCount('applications', 0);
        $this->assertSame(1, $worker->tokens()->count());
    }

    public function test_トークンは期限の日数のあいだは使え過ぎたら401(): void
    {
        // 期限は config('myteam.worker_token_days') 日（5.3）。config/sanctum.php の expiration（作成からの分数）が
        // もっと短く設定されると、期限の前でも切れてしまう。その設定の混入をここで止める
        $worker = $this->registeredWorker();
        $token = $this->workerToken($worker);
        $days = (int) config('myteam.worker_token_days');
        Context::forget('company_id');

        $this->travel($days)->days();
        $this->travel(-1)->minutes();
        $this->withToken($token)->getJson('/api/worker/me')->assertOk();
        $this->forgetAuthenticatedUsers();

        $this->travel(2)->minutes();
        $this->withToken($token)->getJson('/api/worker/me')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_掃除のコマンドは期限が切れて24時間たったトークンだけ消す(): void
    {
        // routes/console.php が毎日回す sanctum:prune-expired --hours=24 が、差し替えたトークンのモデル（App\Models\PersonalAccessToken）で動く
        $worker = $this->registeredWorker();
        $this->workerToken($worker, expiresAt: now()->subHours(25), deviceName: '古い端末');
        $this->workerToken($worker, expiresAt: now()->subHours(23), deviceName: '切れたばかりの端末');
        $this->workerToken($worker, deviceName: '今の端末');

        $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['切れたばかりの端末', '今の端末'], $worker->tokens()->pluck('name')->all());
    }

    public function test_初回登録で受け取ったトークンで自分の情報を取得できる(): void
    {
        $this->company->forceFill(['name' => '株式会社サンプル', 'code' => 'SMPK23'])->save();
        $worker = $this->invitedWorker('taro@example.com');
        Context::forget('company_id');

        $token = $this->postJson('/api/worker/register', $this->registerPayload())->assertCreated()->json('data.token');

        $this->withToken($token)
            ->getJson('/api/worker/me')
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $worker->id,
                'name' => '山田 太郎',
                'email' => 'taro@example.com',
                'company' => ['id' => $this->company->id, 'name' => '株式会社サンプル', 'code' => 'SMPK23'],
            ]]);
    }

    public function test_招待コードを小文字や前後の空白つきで入れても登録できる(): void
    {
        $worker = $this->invitedWorker('taro@example.com');
        Context::forget('company_id');

        $this->postJson('/api/worker/register', $this->registerPayload(['code' => '  abcd2345 ']))
            ->assertCreated()
            ->assertJsonPath('data.worker.id', $worker->id);
    }

    public function test_期限切れと使用済みと取り消しとメール不一致と存在しないコードは同じ422でerrors_code(): void
    {
        // どの理由でも同じ文言・同じ項目（招待やアカウントの有無を明かさない。docs/01 の 4.5 の ①②）
        $expired = $this->invitedWorker('expired@example.com', 'EXPD2345');
        $expired->invitations()->sole()->forceFill(['expires_at' => now()->subMinute()])->save();

        $used = Worker::factory()->create(['email' => 'used@example.com']);
        WorkerInvitation::factory()->used()->create(['worker_id' => $used->id, 'code_hash' => WorkerInvitation::hashCode('USED2345')]);

        $revoked = Worker::factory()->create(['email' => 'revoked@example.com']);
        WorkerInvitation::factory()->revoked()->create(['worker_id' => $revoked->id, 'code_hash' => WorkerInvitation::hashCode('RVKD2345')]);

        $this->invitedWorker('mismatch@example.com', 'MSMT2345');
        Context::forget('company_id');

        $cases = [
            '期限切れ' => ['email' => 'expired@example.com', 'code' => 'EXPD2345'],
            '使用済み' => ['email' => 'used@example.com', 'code' => 'USED2345'],
            '取り消し' => ['email' => 'revoked@example.com', 'code' => 'RVKD2345'],
            'メール不一致' => ['email' => 'other@example.com', 'code' => 'MSMT2345'],
            '存在しないコード' => ['email' => 'nobody@example.com', 'code' => 'ZZZZ9999'],
        ];

        foreach ($cases as $label => $input) {
            $this->postJson('/api/worker/register', $this->registerPayload($input))
                ->assertUnprocessable()
                ->assertExactJson(['message' => self::INVALID_CODE_MESSAGE, 'errors' => ['code' => [self::INVALID_CODE_MESSAGE]]]);
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(0, Worker::withoutGlobalScopes()->whereNotNull('registered_at')->count());
    }

    public function test_メールの大文字小文字を変えても登録できログイン用メールは小文字で入る(): void
    {
        $worker = $this->invitedWorker('Taro.Yamada@Example.com');
        Context::forget('company_id');

        $this->postJson('/api/worker/register', $this->registerPayload(['email' => 'TARO.YAMADA@EXAMPLE.COM']))
            ->assertCreated()
            ->assertJsonPath('data.worker.email', 'taro.yamada@example.com');

        $worker = CurrentCompany::runAs($this->company->id, fn () => $worker->fresh());
        $this->assertSame('taro.yamada@example.com', $worker->login_email);
        // 連絡用のメール（email）は管理者が入れたまま
        $this->assertSame('Taro.Yamada@Example.com', $worker->email);
    }

    public function test_無効ワーカーは初回登録できない(): void
    {
        $worker = $this->invitedWorker('taro@example.com', attributes: ['is_active' => false]);
        Context::forget('company_id');

        // ②（コード・メール）を先に見る: メールが合わなければ無効かどうかを明かさない
        $this->postJson('/api/worker/register', $this->registerPayload(['email' => 'other@example.com']))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['code' => [self::INVALID_CODE_MESSAGE]]);

        $this->postJson('/api/worker/register', $this->registerPayload())
            ->assertUnprocessable()
            ->assertExactJson(['message' => self::DISABLED_MESSAGE, 'errors' => ['email' => [self::DISABLED_MESSAGE]]]);

        $this->assertRegisterRolledBack($worker);
    }

    public function test_同じ企業の登録済みワーカーとメールが重なると初回登録できない(): void
    {
        $this->registeredWorker(['name' => '山田 花子', 'email' => 'taro@example.com']);
        $worker = $this->invitedWorker('Taro@Example.com');
        Context::forget('company_id');

        $this->postJson('/api/worker/register', $this->registerPayload())
            ->assertUnprocessable()
            ->assertExactJson(['message' => self::EMAIL_TAKEN_MESSAGE, 'errors' => ['email' => [self::EMAIL_TAKEN_MESSAGE]]]);

        $this->assertRegisterRolledBack($worker);
    }

    public function test_確認の後に同じメールで登録されてもunique違反は同じ422でerrors_email(): void
    {
        // ④ の確認の後・保存の前に、同じメールの別のワーカーが登録を済ませた競合を再現する（保存の直前に割り込む）
        $worker = $this->invitedWorker('taro@example.com');
        $other = Worker::factory()->create(['name' => '山田 花子', 'email' => 'taro@example.com']);
        $raced = false;
        Worker::saving(function (Worker $saving) use ($worker, $other, &$raced) {
            if ($raced || $saving->id !== $worker->id) {
                return;
            }
            $raced = true;
            Worker::query()->whereKey($other->id)->update(['login_email' => 'taro@example.com', 'registered_at' => now()]);
        });
        Context::forget('company_id');

        $this->postJson('/api/worker/register', $this->registerPayload())
            ->assertUnprocessable()
            ->assertExactJson(['message' => self::EMAIL_TAKEN_MESSAGE, 'errors' => ['email' => [self::EMAIL_TAKEN_MESSAGE]]]);

        $this->assertTrue($raced);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        // 招待の使用済みもロールバックされる
        $this->assertSame(0, WorkerInvitation::withoutGlobalScopes()->whereNotNull('used_at')->count());
    }

    public function test_招待を引いた後でロックの前に取り消しやメールの変更が入ると同じ422でロールバックする(): void
    {
        // ① はロックせずに招待を引き、ワーカー → 招待の順にロックして読み直す（docs/01 の 4.5）。
        // ① の直後（最初の retrieved）に割り込み、読み直した今の値で ② が判定することを確かめる
        $revoked = $this->invitedWorker('revoked@example.com', 'RVKD2345');
        $changed = $this->invitedWorker('changed@example.com', 'CHGD2345');
        $race = null;
        WorkerInvitation::retrieved(function () use (&$race) {
            if ($race !== null) {
                [$fn, $race] = [$race, null];
                $fn();
            }
        });
        Context::forget('company_id');

        $cases = [
            '取り消し' => [$revoked, 'RVKD2345', fn () => WorkerInvitation::withoutGlobalScopes()
                ->where('worker_id', $revoked->id)->update(['revoked_at' => now(), 'code' => null])],
            'メールの変更' => [$changed, 'CHGD2345', fn () => Worker::withoutGlobalScopes()
                ->whereKey($changed->id)->update(['email' => 'new@example.com'])],
        ];

        foreach ($cases as $label => [$worker, $code, $fn]) {
            $race = $fn;
            $this->postJson('/api/worker/register', $this->registerPayload(['email' => $worker->email, 'code' => $code]))
                ->assertUnprocessable()
                ->assertExactJson(['message' => self::INVALID_CODE_MESSAGE, 'errors' => ['code' => [self::INVALID_CODE_MESSAGE]]]);
            $this->assertNull($race, "{$label}: 割り込みが動いていない");

            $worker = Worker::withoutGlobalScopes()->find($worker->id);
            $this->assertNull($worker->registered_at, $label);
            $this->assertNull($worker->login_email, $label);
            $this->assertNull($worker->getRawOriginal('password'), $label);
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
        // 招待は使用済みにならない（割り込みの更新は同じトランザクションの中で走るので、その更新も一緒にロールバックされる。
        // 本物の競合では相手のトランザクションが先にコミットしているので、取り消し・メールの変更は残る）
        $this->assertSame(0, WorkerInvitation::withoutGlobalScopes()->whereNotNull('used_at')->count());
    }

    public function test_別の企業の登録済みワーカーと同じメールなら初回登録できる(): void
    {
        // 自社に登録済みの taro がいても、別の企業の招待なら登録できる。企業は招待から決まる（スコープを外して引くのは招待の 1 行だけ）
        $this->registeredWorker(['name' => '山田 花子', 'email' => 'taro@example.com']);
        $other = Company::factory()->create(['name' => '株式会社ほか']);
        $other->forceFill(['code' => 'ZXCV23'])->save();
        $worker = CurrentCompany::runAs($other->id, fn () => $this->invitedWorker('taro@example.com'));
        Context::forget('company_id');

        $this->postJson('/api/worker/register', $this->registerPayload())
            ->assertCreated()
            ->assertJsonPath('data.worker.id', $worker->id)
            ->assertJsonPath('data.worker.email', 'taro@example.com')
            ->assertJsonPath('data.worker.company', ['id' => $other->id, 'name' => '株式会社ほか', 'code' => 'ZXCV23']);

        $this->assertSame($other->id, $worker->tokens()->sole()->tokenable->company_id);
        // 企業はリクエストの後に残らない（runAs が戻す）
        $this->assertFalse(CurrentCompany::check());
    }

    public function test_登録済みのワーカーに使える招待が残っていても登録をやり直せない(): void
    {
        // 本来は登録で招待が使用済みになるが、行が残っていてもパスワードを上書きさせない
        $worker = $this->registeredWorker(['name' => '山田 太郎', 'email' => 'taro@example.com']);
        WorkerInvitation::factory()->create(['worker_id' => $worker->id, 'code' => self::CODE]);
        Context::forget('company_id');

        $this->postJson('/api/worker/register', $this->registerPayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['code' => [self::INVALID_CODE_MESSAGE]]);

        $worker = CurrentCompany::runAs($this->company->id, fn () => $worker->fresh());
        $this->assertTrue(Hash::check('password', $worker->password));
    }

    public function test_初回登録は6回目から429(): void
    {
        $this->invitedWorker('taro@example.com');
        Context::forget('company_id');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/worker/register', $this->registerPayload(['email' => 'Taro@example.com', 'code' => 'ZZZZ9999']))
                ->assertUnprocessable();
        }

        // メールは小文字にして数える（大文字小文字を変えて枠をすり抜けない）。正しいコードでも 6 回目は 429
        $this->postJson('/api/worker/register', $this->registerPayload())
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('errors', [])
            ->assertJsonPath('message', fn (string $message) => preg_match('/^初回登録の試行回数が多すぎます。\d+ 秒後にお試しください。$/u', $message) === 1);

        // 別のメールは同じ IP でも数えない
        $this->postJson('/api/worker/register', $this->registerPayload(['email' => 'hanako@example.com', 'code' => 'ZZZZ9999']))
            ->assertUnprocessable();
    }

    public function test_初回登録の入力が足りないと422で日本語のerrors(): void
    {
        Context::forget('company_id');

        $this->postJson('/api/worker/register', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email', ['メールアドレスは必須項目です。'])
            ->assertJsonPath('errors.code', ['招待コードは必須項目です。'])
            ->assertJsonPath('errors.password', ['パスワードは必須項目です。'])
            ->assertJsonPath('errors.device_name', ['端末名は必須項目です。']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_telescopeは招待コードとパスワードと発行したトークンを記録しない(): void
    {
        $statics = [Telescope::$hiddenRequestParameters, Telescope::$hiddenResponseParameters];

        try {
            $this->app->register(TelescopeServiceProvider::class);

            foreach (['code', 'password', 'password_confirmation'] as $parameter) {
                $this->assertContains($parameter, Telescope::$hiddenRequestParameters, "パラメータ {$parameter} を隠す");
            }
            $this->assertContains('data.token', Telescope::$hiddenResponseParameters, '応答のトークンを隠す');
        } finally {
            // static はプロセスの間残るので、後続のテストに持ち越さない
            [Telescope::$hiddenRequestParameters, Telescope::$hiddenResponseParameters] = $statics;
        }
    }

    // ---- 初回登録: 判定の順（②→③→④）の境界（docs/01 の 4.5） ----

    public function test_無効で招待も使えないワーカーは無効を明かさずerrors_code(): void
    {
        // ② が ③ より先: 招待が期限切れ・使用済みなら、無効かどうかを答えない
        $expired = $this->invitedWorker('expired@example.com', 'EXPD2345', ['is_active' => false]);
        $expired->invitations()->sole()->forceFill(['expires_at' => now()->subMinute()])->save();
        $used = Worker::factory()->create(['email' => 'used@example.com', 'is_active' => false]);
        WorkerInvitation::factory()->used()->create(['worker_id' => $used->id, 'code_hash' => WorkerInvitation::hashCode('USED2345')]);
        Context::forget('company_id');

        foreach ([['expired@example.com', 'EXPD2345'], ['used@example.com', 'USED2345']] as [$email, $code]) {
            $this->postJson('/api/worker/register', $this->registerPayload(['email' => $email, 'code' => $code]))
                ->assertUnprocessable()
                ->assertExactJson(['message' => self::INVALID_CODE_MESSAGE, 'errors' => ['code' => [self::INVALID_CODE_MESSAGE]]]);
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_無効で登録済みのメールとも重なるワーカーは無効の文言(): void
    {
        // ③ が ④ より先
        $this->registeredWorker(['name' => '山田 花子', 'email' => 'taro@example.com']);
        $worker = $this->invitedWorker('taro@example.com', attributes: ['is_active' => false]);
        Context::forget('company_id');

        $this->postJson('/api/worker/register', $this->registerPayload())
            ->assertUnprocessable()
            ->assertExactJson(['message' => self::DISABLED_MESSAGE, 'errors' => ['email' => [self::DISABLED_MESSAGE]]]);

        $this->assertRegisterRolledBack($worker);
    }

    public function test_メールが合わなければ登録済みのメールと重なっても重なりを明かさずerrors_code(): void
    {
        // ② が ④ より先: 入力のメールが招待の相手と合わなければ、そのメールが登録済みかを答えない
        $this->registeredWorker(['name' => '山田 花子', 'email' => 'hanako@example.com']);
        $worker = $this->invitedWorker('taro@example.com');
        Context::forget('company_id');

        $this->postJson('/api/worker/register', $this->registerPayload(['email' => 'hanako@example.com']))
            ->assertUnprocessable()
            ->assertExactJson(['message' => self::INVALID_CODE_MESSAGE, 'errors' => ['code' => [self::INVALID_CODE_MESSAGE]]]);

        $this->assertRegisterRolledBack($worker);
    }

    public function test_同じ企業の別のワーカーのメールで他人の招待コードを使うと422で両方とも登録されない(): void
    {
        $taro = $this->invitedWorker('taro@example.com');
        $hanako = $this->invitedWorker('hanako@example.com', 'HNKO2345');
        Context::forget('company_id');

        // 太郎のコード + 花子のメール
        $this->postJson('/api/worker/register', $this->registerPayload(['email' => 'hanako@example.com']))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['code' => [self::INVALID_CODE_MESSAGE]]);

        $this->assertRegisterRolledBack($taro);
        [$hanako, $hanakoInvitation] = CurrentCompany::runAs($this->company->id, fn () => [$hanako->fresh(), $hanako->invitations()->sole()]);
        $this->assertNull($hanako->registered_at);
        $this->assertTrue($hanakoInvitation->isUsable());
    }

    public function test_招待の後にワーカーのメールが変わったら今のメールで照合する(): void
    {
        // ② は招待を出したときのメールではなく、今の workers.email と比べる
        $worker = $this->invitedWorker('old@example.com');
        Worker::query()->whereKey($worker->id)->update(['email' => 'New@Example.com']);
        Context::forget('company_id');

        $this->postJson('/api/worker/register', $this->registerPayload(['email' => 'old@example.com']))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['code' => [self::INVALID_CODE_MESSAGE]]);

        $this->postJson('/api/worker/register', $this->registerPayload(['email' => 'new@example.com']))
            ->assertCreated()
            ->assertJsonPath('data.worker.id', $worker->id);

        $worker = CurrentCompany::runAs($this->company->id, fn () => $worker->fresh());
        $this->assertSame('new@example.com', $worker->login_email);
    }

    public function test_取り消した招待のコードは使えず新しい招待のコードで登録できる(): void
    {
        $worker = $this->invitedWorker('taro@example.com', 'OLDC2345');
        $worker->invitations()->sole()->forceFill(['revoked_at' => now(), 'code' => null])->save();
        WorkerInvitation::factory()->create(['worker_id' => $worker->id, 'code' => self::CODE]);
        Context::forget('company_id');

        $this->postJson('/api/worker/register', $this->registerPayload(['code' => 'OLDC2345']))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['code' => [self::INVALID_CODE_MESSAGE]]);

        $this->postJson('/api/worker/register', $this->registerPayload())
            ->assertCreated()
            ->assertJsonPath('data.worker.id', $worker->id);
    }

    // ---- 初回登録: 企業の切り替え（runAs。docs/01 の 4.2・5.3） ----

    public function test_他社の招待で登録しても自社の同じメールのワーカーには触れない(): void
    {
        // 自社にも同じメールの未登録ワーカーと招待がある。他社のコードで登録すると他社のワーカーだけが登録される
        $own = $this->invitedWorker('taro@example.com', 'OWNC2345');
        $other = Company::factory()->create();
        $otherWorker = CurrentCompany::runAs($other->id, fn () => $this->invitedWorker('taro@example.com'));
        Context::forget('company_id');

        $this->postJson('/api/worker/register', $this->registerPayload())
            ->assertCreated()
            ->assertJsonPath('data.worker.id', $otherWorker->id)
            ->assertJsonPath('data.worker.company.id', $other->id);

        // 自社のワーカーと招待はそのまま
        [$own, $ownInvitation] = CurrentCompany::runAs($this->company->id, fn () => [$own->fresh(), $own->invitations()->sole()]);
        $this->assertNull($own->registered_at);
        $this->assertNull($own->login_email);
        $this->assertTrue($ownInvitation->isUsable());
        $this->assertSame('OWNC2345', $ownInvitation->code);
        $this->assertSame(0, $own->tokens()->count());

        // 他社のワーカーが登録され、他社の招待が使用済みになる
        $otherInvitation = CurrentCompany::runAs($other->id, fn () => $otherWorker->invitations()->sole());
        $this->assertNotNull($otherInvitation->used_at);
        $this->assertNull($otherInvitation->getRawOriginal('code'));
        $this->assertSame(1, $otherWorker->tokens()->count());
    }

    public function test_サービスの初回登録は成功しても失敗しても呼び出し前の企業に戻す(): void
    {
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, function () {
            $this->invitedWorker('taro@example.com');
            $this->invitedWorker('jiro@example.com', 'JIRO2345', ['is_active' => false]);
        });
        CurrentCompany::set($this->company->id);
        $service = app(WorkerAuthService::class);

        $result = $service->register('taro@example.com', self::CODE, 'secret-pass', 'iPhone 16');
        $this->assertSame($other->id, $result['worker']->company_id);
        $this->assertSame($this->company->id, CurrentCompany::id());

        try {
            $service->register('jiro@example.com', 'JIRO2345', 'secret-pass', 'iPhone 16');
            $this->fail('無効ワーカーの登録が通った');
        } catch (DomainException $e) {
            $this->assertSame(self::DISABLED_MESSAGE, $e->getMessage());
            $this->assertSame('email', $e->field);
        }
        $this->assertSame($this->company->id, CurrentCompany::id());

        // 企業が決まっていない状態から呼べば、決まっていない状態に戻る
        Context::forget('company_id');
        try {
            $service->register('nobody@example.com', 'ZZZZ9999', 'secret-pass', 'iPhone 16');
            $this->fail('存在しないコードの登録が通った');
        } catch (DomainException $e) {
            $this->assertSame('code', $e->field);
        }
        $this->assertFalse(CurrentCompany::check());
    }

    // ---- 初回登録: 入力の検証（docs/01 の 6.11） ----

    public function test_パスワードの確認が合わないと422でerrors_passwordだけ(): void
    {
        $worker = $this->invitedWorker('taro@example.com');
        Context::forget('company_id');

        $response = $this->postJson('/api/worker/register', $this->registerPayload(['password_confirmation' => 'secret-pasS']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
        $this->assertSame(['password'], array_keys($response->json('errors')));

        $this->assertRegisterRolledBack($worker);
    }

    public function test_メールアドレスの形式が誤りなら422で日本語のerrors_email(): void
    {
        $worker = $this->invitedWorker('taro@example.com');
        Context::forget('company_id');

        $this->postJson('/api/worker/register', $this->registerPayload(['email' => 'not-an-email']))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['email' => ['メールアドレスは、有効なメールアドレス形式で指定してください。']]);

        $this->assertRegisterRolledBack($worker);
    }

    public function test_パスワードは8文字から255文字まで(): void
    {
        $this->invitedWorker('taro@example.com');
        Context::forget('company_id');

        foreach ([str_repeat('a', 7), str_repeat('a', 256)] as $password) {
            $this->postJson('/api/worker/register', $this->registerPayload(['password' => $password, 'password_confirmation' => $password]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['password'])
                ->assertJsonPath('errors.password.0', fn (string $m) => str_starts_with($m, 'パスワード'));
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $password = str_repeat('a', 255);
        $this->postJson('/api/worker/register', $this->registerPayload(['password' => $password, 'password_confirmation' => $password]))
            ->assertCreated();
    }

    public function test_端末名は255文字まででトークンの名前になる(): void
    {
        $this->invitedWorker('taro@example.com');
        Context::forget('company_id');

        foreach ([['iPhone'], str_repeat('a', 256)] as $deviceName) {
            $this->postJson('/api/worker/register', $this->registerPayload(['device_name' => $deviceName]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['device_name'])
                ->assertJsonPath('errors.device_name.0', fn (string $m) => str_starts_with($m, '端末名'));
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $name = str_repeat('端', 255);
        $token = $this->postJson('/api/worker/register', $this->registerPayload(['device_name' => $name]))
            ->assertCreated()
            ->json('data.token');

        $this->assertSame($name, PersonalAccessToken::findToken($token)->name);
    }

    public function test_招待コードは空白を除いて8文字でないと検証で弾きサービスまで届かない(): void
    {
        $worker = $this->invitedWorker('taro@example.com');
        Context::forget('company_id');

        // 前後の空白は数えない（TrimStrings が落とした後で 8 文字）。空白つきの 7 文字・9 文字も弾く
        foreach (['ABCD234', 'ABCD23456', ' ABCD234 ', ' ABCD23456 '] as $code) {
            $this->postJson('/api/worker/register', $this->registerPayload(['code' => $code]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['code'])
                ->assertJsonPath('errors.code.0', fn (string $m) => str_starts_with($m, '招待コード') && $m !== self::INVALID_CODE_MESSAGE);
        }

        $this->assertRegisterRolledBack($worker);
    }

    // ---- ログイン（F-29。POST /api/worker/login。docs/01 の 4.5・5.3・6.11） ----
    // 判定は Timebox で包むので、失敗のたびに Sleep で残りの時間を待つ。テストでは Sleep::fake() で実際には待たず、待ったかどうかを確かめる

    public function test_企業コードとメールとパスワードでログインでき端末名と期限つきのトークンが返る(): void
    {
        $this->freezeSecond();
        $worker = $this->prepareLogin();

        $response = $this->postJson('/api/worker/login', $this->loginPayload())
            ->assertOk()
            ->assertJsonPath('data.worker', [
                'id' => $worker->id,
                'name' => '山田 太郎',
                'email' => 'taro@example.com',
                'company' => ['id' => $this->company->id, 'name' => '株式会社サンプル', 'code' => 'SMPK23'],
            ]);
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertEqualsCanonicalizing(['token', 'worker'], array_keys($response->json('data')));

        // トークンは端末名・能力 ['worker'] ちょうど・設定の日数後に切れる
        $token = $worker->tokens()->sole();
        $this->assertSame('iPhone 16', $token->name);
        $this->assertSame(['worker'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue(now()->addDays((int) config('myteam.worker_token_days'))->equalTo($token->expires_at));
        $this->assertSame($token->id, (int) explode('|', $response->json('data.token'))[0]);

        // 成功は待たずに返す（Timebox の returnEarly）。企業はリクエストの後に残らない
        Sleep::assertNeverSlept();
        $this->assertFalse(CurrentCompany::check());

        // 受け取ったトークンでワーカーの API に入れる
        $this->withToken($response->json('data.token'))->getJson('/api/worker/me')->assertOk()->assertJsonPath('data.id', $worker->id);
    }

    public function test_企業コードとメールは大文字小文字を問わない(): void
    {
        $worker = $this->prepareLogin();

        $this->postJson('/api/worker/login', $this->loginPayload(['company_code' => 'smPk23', 'email' => 'TARO@Example.COM']))
            ->assertOk()
            ->assertJsonPath('data.worker.id', $worker->id)
            ->assertJsonPath('data.worker.email', 'taro@example.com');
    }

    public function test_企業コードとメールとパスワードのどれが誤りでも同じ422でerrors_email(): void
    {
        $this->prepareLogin();
        // 他社の企業コード（その企業に taro はいない）も、存在しない企業コードと同じ
        Company::factory()->create()->forceFill(['code' => 'ZXCV23'])->save();

        $cases = [
            '存在しない企業コード' => ['company_code' => 'QQQQ99'],
            '他社の企業コード' => ['company_code' => 'ZXCV23'],
            '存在しないメール' => ['email' => 'hanako@example.com'],
            'パスワードの誤り' => ['password' => 'wrong-pass'],
        ];
        foreach ($cases as $label => $overrides) {
            // 見つからないときもダミーのハッシュでパスワードを 1 回照合する（照合の有無で応答時間が変わらないように）
            $this->expectOnePasswordCheck($overrides['password'] ?? 'secret-pass');

            $this->postJson('/api/worker/login', $this->loginPayload($overrides))
                ->assertUnprocessable()
                ->assertExactJson(['message' => self::INVALID_CREDENTIALS_MESSAGE, 'errors' => ['email' => [self::INVALID_CREDENTIALS_MESSAGE]]]);
        }

        // 失敗はどれも Timebox の残りを待つ（早く返さない）
        Sleep::assertSleptTimes(count($cases));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_未登録のワーカーはログインできず誤りと同じ文言(): void
    {
        $worker = $this->prepareLogin(registered: false);

        // password が NULL でも、ダミーのハッシュで照合して時間をそろえる
        $this->expectOnePasswordCheck('secret-pass');

        $this->postJson('/api/worker/login', $this->loginPayload())
            ->assertUnprocessable()
            ->assertExactJson(['message' => self::INVALID_CREDENTIALS_MESSAGE, 'errors' => ['email' => [self::INVALID_CREDENTIALS_MESSAGE]]]);

        Sleep::assertSleptTimes(1);
        $this->assertSame(0, $worker->tokens()->count());
    }

    public function test_無効ワーカーは正しいパスワードでもログインできない(): void
    {
        $worker = $this->prepareLogin(['is_active' => false]);

        $this->postJson('/api/worker/login', $this->loginPayload())
            ->assertUnprocessable()
            ->assertExactJson(['message' => self::DISABLED_MESSAGE, 'errors' => ['email' => [self::DISABLED_MESSAGE]]]);

        Sleep::assertSleptTimes(1);
        $this->assertSame(0, $worker->tokens()->count());
    }

    public function test_無効ワーカーでもパスワードが誤りなら誤りの文言(): void
    {
        $this->prepareLogin(['is_active' => false]);

        // パスワードを知らない人に、無効かどうか（アカウントがあること）を明かさない
        $this->expectOnePasswordCheck('wrong-pass');

        $this->postJson('/api/worker/login', $this->loginPayload(['password' => 'wrong-pass']))
            ->assertUnprocessable()
            ->assertExactJson(['message' => self::INVALID_CREDENTIALS_MESSAGE, 'errors' => ['email' => [self::INVALID_CREDENTIALS_MESSAGE]]]);

        Sleep::assertSleptTimes(1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_同じメールの2社のアカウントは企業コードでログイン先が決まる(): void
    {
        $mine = $this->prepareLogin();
        $other = Company::factory()->create(['name' => '株式会社ほか']);
        $other->forceFill(['code' => 'ZXCV23'])->save();
        $theirs = CurrentCompany::runAs($other->id, fn () => $this->registeredWorker(['email' => 'taro@example.com', 'password' => 'other-pass']));

        $this->postJson('/api/worker/login', $this->loginPayload(['company_code' => 'ZXCV23', 'password' => 'other-pass']))
            ->assertOk()
            ->assertJsonPath('data.worker.id', $theirs->id)
            ->assertJsonPath('data.worker.company', ['id' => $other->id, 'name' => '株式会社ほか', 'code' => 'ZXCV23']);

        // パスワードは企業ごとのアカウントのもの。自社のパスワードでは他社にログインできない
        $this->postJson('/api/worker/login', $this->loginPayload(['company_code' => 'ZXCV23']))
            ->assertUnprocessable()
            ->assertJsonPath('message', self::INVALID_CREDENTIALS_MESSAGE);

        $this->postJson('/api/worker/login', $this->loginPayload())
            ->assertOk()
            ->assertJsonPath('data.worker.id', $mine->id)
            ->assertJsonPath('data.worker.company.code', 'SMPK23');

        $this->assertSame(1, $mine->tokens()->count());
        $this->assertSame(1, $theirs->tokens()->count());
    }

    public function test_ログインは6回目から429で企業コードとメールごとに数える(): void
    {
        $this->prepareLogin();

        // 企業コードは大文字、メールは小文字にして数える（表記を変えて枠をすり抜けない）
        foreach (['SMPK23', 'smpk23', 'Smpk23', 'SMPK23', 'smpk23'] as $i => $code) {
            $this->postJson('/api/worker/login', $this->loginPayload([
                'company_code' => $code,
                'email' => $i % 2 === 0 ? 'taro@example.com' : 'Taro@Example.com',
                'password' => 'wrong-pass',
            ]))->assertUnprocessable();
        }

        // 正しいパスワードでも 6 回目は 429（共通の文言はログインの試行回数のもの）
        $response = $this->postJson('/api/worker/login', $this->loginPayload())
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', fn (string $message) => preg_match('/^ログインの試行回数が多すぎます。\d+ 秒後にお試しください。$/u', $message) === 1);
        // errors は空の配列 [] ではなく空のオブジェクト {}（assertJsonPath('errors', []) はどちらでも通るので本文の文字列で見る）
        $this->assertStringContainsString('"errors":{}', $response->getContent());

        // 同じ IP でも、メールが違えば・企業コードが違えば別に数える
        $this->postJson('/api/worker/login', $this->loginPayload(['email' => 'hanako@example.com']))->assertUnprocessable();
        $this->postJson('/api/worker/login', $this->loginPayload(['company_code' => 'QQQQ99']))->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_ログインの入力が足りないと422で日本語のerrors(): void
    {
        Context::forget('company_id');

        $this->postJson('/api/worker/login', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.company_code', ['企業コードは必須項目です。'])
            ->assertJsonPath('errors.email', ['メールアドレスは必須項目です。'])
            ->assertJsonPath('errors.password', ['パスワードは必須項目です。'])
            ->assertJsonPath('errors.device_name', ['端末名は必須項目です。']);
    }

    public function test_無効で未登録のワーカーは無効を明かさず誤りの文言(): void
    {
        $worker = $this->prepareLogin(['is_active' => false], registered: false);

        // パスワードを持たないワーカーに「無効」と答えると、アカウントがあることが分かる。未登録と同じ扱いにする
        $this->expectOnePasswordCheck('secret-pass');

        $this->postJson('/api/worker/login', $this->loginPayload())
            ->assertUnprocessable()
            ->assertExactJson(['message' => self::INVALID_CREDENTIALS_MESSAGE, 'errors' => ['email' => [self::INVALID_CREDENTIALS_MESSAGE]]]);

        Sleep::assertSleptTimes(1);
        $this->assertSame(0, $worker->tokens()->count());
    }

    public function test_企業コードとメールの前後の空白は無視される(): void
    {
        $worker = $this->prepareLogin();

        $this->postJson('/api/worker/login', $this->loginPayload(['company_code' => "  smpk23\t", 'email' => ' Taro@Example.com ']))
            ->assertOk()
            ->assertJsonPath('data.worker.id', $worker->id)
            ->assertJsonPath('data.worker.company.code', 'SMPK23');
    }

    public function test_ログインで受け取ったトークンでは管理者のapiに入れない(): void
    {
        $this->prepareLogin();

        $token = $this->postJson('/api/worker/login', $this->loginPayload())->assertOk()->json('data.token');
        $this->forgetAuthenticatedUsers();

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        $this->assertGuest('sanctum');
        $this->withToken($token)->getJson('/api/job-postings')->assertUnauthorized();
    }

    public function test_2つの端末でログインするとトークンが2本になりログアウトは今の1本だけ消す(): void
    {
        $worker = $this->prepareLogin();

        $phone = $this->postJson('/api/worker/login', $this->loginPayload(['device_name' => 'iPhone 16']))->assertOk()->json('data.token');
        $tablet = $this->postJson('/api/worker/login', $this->loginPayload(['device_name' => 'iPad']))->assertOk()->json('data.token');
        $this->assertNotSame($phone, $tablet);
        $this->assertEqualsCanonicalizing(['iPhone 16', 'iPad'], $worker->tokens()->pluck('name')->all());

        $this->withToken($phone)->postJson('/api/worker/logout')->assertNoContent();
        $this->forgetAuthenticatedUsers();

        $this->withToken($phone)->getJson('/api/worker/me')->assertUnauthorized();
        $this->forgetAuthenticatedUsers();
        $this->withToken($tablet)->getJson('/api/worker/me')->assertOk()->assertJsonPath('data.id', $worker->id);
        $this->assertSame(['iPad'], $worker->tokens()->pluck('name')->all());
    }

    public function test_サービスのログインは成功しても失敗しても呼び出し前の企業に戻す(): void
    {
        Sleep::fake();
        $other = Company::factory()->create();
        $other->forceFill(['code' => 'ZXCV23'])->save();
        CurrentCompany::runAs($other->id, function () {
            $this->registeredWorker(['email' => 'taro@example.com', 'password' => 'secret-pass']);
            $this->registeredWorker(['email' => 'jiro@example.com', 'password' => 'secret-pass', 'is_active' => false]);
        });
        CurrentCompany::set($this->company->id);
        $service = app(WorkerAuthService::class);

        $result = $service->login('ZXCV23', 'taro@example.com', 'secret-pass', 'iPhone 16');
        $this->assertSame($other->id, $result['worker']->company_id);
        $this->assertSame($this->company->id, CurrentCompany::id());

        foreach ([
            ['jiro@example.com', 'secret-pass', self::DISABLED_MESSAGE],
            ['taro@example.com', 'wrong-pass', self::INVALID_CREDENTIALS_MESSAGE],
        ] as [$email, $password, $message]) {
            try {
                $service->login('ZXCV23', $email, $password, 'iPhone 16');
                $this->fail("{$email} のログインが通った");
            } catch (DomainException $e) {
                $this->assertSame($message, $e->getMessage());
                $this->assertSame('email', $e->field);
            }
            $this->assertSame($this->company->id, CurrentCompany::id());
        }

        // 企業が決まっていない状態から呼べば、成功しても失敗しても決まっていない状態に戻る
        Context::forget('company_id');
        $service->login('ZXCV23', 'taro@example.com', 'secret-pass', 'iPad');
        $this->assertFalse(CurrentCompany::check());
        try {
            $service->login('ZXCV23', 'taro@example.com', 'wrong-pass', 'iPad');
            $this->fail('誤ったパスワードのログインが通った');
        } catch (DomainException) {
        }
        $this->assertFalse(CurrentCompany::check());
    }

    public function test_ログインの_timeboxは成功では待たず失敗では残りを待ち成功の後の失敗も待つ(): void
    {
        $this->prepareLogin();
        // 同じインスタンスで続けて呼ぶ（Timebox を使い回すと、前の成功の returnEarly が残って失敗でも待たなくなる）
        $service = app(WorkerAuthService::class);

        $service->login('SMPK23', 'taro@example.com', 'secret-pass', 'iPhone 16');
        Sleep::assertNeverSlept();

        try {
            $service->login('SMPK23', 'taro@example.com', 'wrong-pass', 'iPhone 16');
            $this->fail('誤ったパスワードのログインが通った');
        } catch (DomainException) {
        }

        // 待つのは 200 ミリ秒から判定にかかった時間を引いた残り（0 より長く 200 ミリ秒以下）
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration) => $duration->totalMicroseconds > 0 && $duration->totalMicroseconds <= 200_000);
    }

    public function test_ダミーのハッシュはキャッシュから読み見つからない道でも未登録の道でも作らない(): void
    {
        // 本番（PHP-FPM）はリクエストごとにプロセスの static が消える。キャッシュに入っていれば、どのリクエストでも作らずに読むことを確かめる
        $this->prepareLogin(['email' => 'hanako@example.com'], registered: false);
        $dummy = Hash::make('どの入力とも合わないダミー');
        Cache::forever(WorkerAuthService::dummyHashCacheKey(), $dummy);

        foreach ([
            '存在しない企業コード' => ['company_code' => 'QQQQ99'],
            '存在しないメール' => ['email' => 'nobody@example.com'],
            '未登録のワーカー' => ['email' => 'hanako@example.com'],
        ] as $label => $overrides) {
            $mock = Mockery::mock($real = $this->realHasher ??= $this->app->make('hash'));
            $mock->shouldReceive('make')->never();
            $mock->shouldReceive('check')->once()->with('secret-pass', $dummy)->andReturnUsing(fn (...$args) => $real->check(...$args));
            Hash::swap($mock);

            $this->postJson('/api/worker/login', $this->loginPayload($overrides))
                ->assertUnprocessable()
                ->assertJsonPath('message', self::INVALID_CREDENTIALS_MESSAGE);
        }
    }

    public function test_ダミーのハッシュはパスワード誤りの道でも成功の道でもキャッシュから1回読む(): void
    {
        // キャッシュの読み取りを見つからない道だけにすると、キャッシュが遅い・落ちているときにその道だけ遅くなって分かる。どの道も 1 回にそろえる
        $this->prepareLogin();
        $real = $this->app->make('cache');

        foreach ([
            'パスワードの誤り' => [['password' => 'wrong-pass'], 422],
            '成功' => [[], 200],
            '見つからない' => [['email' => 'nobody@example.com'], 422],
        ] as $label => [$overrides, $status]) {
            $cache = Mockery::mock($real);
            $cache->shouldReceive('rememberForever')
                ->once()
                ->with(WorkerAuthService::dummyHashCacheKey(), Mockery::type('Closure'))
                ->andReturnUsing(fn (...$args) => $real->rememberForever(...$args));
            Cache::swap($cache);

            $this->postJson('/api/worker/login', $this->loginPayload($overrides))->assertStatus($status);
        }
    }

    public function test_ダミーのハッシュはキャッシュが空なら作って入れ次からはそれを使う(): void
    {
        $this->prepareLogin();
        $this->assertFalse(Cache::has(WorkerAuthService::dummyHashCacheKey()));

        $this->postJson('/api/worker/login', $this->loginPayload(['email' => 'nobody@example.com']))->assertUnprocessable();

        // 今のハッシャーの設定で作ったハッシュが入る（照合が本物と同じだけかかるように）
        $dummy = Cache::get(WorkerAuthService::dummyHashCacheKey());
        $this->assertIsString($dummy);
        $this->assertTrue(Hash::isHashed($dummy));
        $this->assertFalse(Hash::needsRehash($dummy));
    }

    public function test_ダミーのハッシュのキャッシュのキーはハッシュのドライバとコストで変わる(): void
    {
        $key = WorkerAuthService::dummyHashCacheKey();
        $this->assertStringStartsWith('worker-login:dummy-hash:bcrypt:', $key);

        // コストを上げたら、古いコストのダミーを使わない（照合の時間が本物とずれるため）
        config(['hashing.bcrypt.rounds' => (int) config('hashing.bcrypt.rounds') + 1]);
        $this->assertNotSame($key, WorkerAuthService::dummyHashCacheKey());

        config(['hashing.driver' => 'argon2id']);
        $this->assertStringStartsWith('worker-login:dummy-hash:argon2id:', WorkerAuthService::dummyHashCacheKey());
    }

    public function test_キャッシュが使えなくてもログインはその場でダミーのハッシュを作って同じ422にし例外を報告する(): void
    {
        $this->prepareLogin();
        Exceptions::fake();
        $cache = Mockery::mock($this->app->make('cache'));
        $cache->shouldReceive('rememberForever')->andThrow(new RuntimeException('キャッシュに接続できない'));
        Cache::swap($cache);
        $this->expectOnePasswordCheck('secret-pass');

        $this->postJson('/api/worker/login', $this->loginPayload(['email' => 'nobody@example.com']))
            ->assertUnprocessable()
            ->assertExactJson(['message' => self::INVALID_CREDENTIALS_MESSAGE, 'errors' => ['email' => [self::INVALID_CREDENTIALS_MESSAGE]]]);

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'キャッシュに接続できない');
    }

    public function test_ログインに成功すると古い設定のハッシュを今の設定で作り直す(): void
    {
        $worker = $this->prepareLogin();
        // 今の設定（テストはコスト 4）と違うコストで作ったハッシュ（コストを上げる前に登録したワーカー）
        $old = password_hash('secret-pass', PASSWORD_BCRYPT, ['cost' => (int) config('hashing.bcrypt.rounds') + 1]);
        DB::table('workers')->where('id', $worker->id)->update(['password' => $old]);
        $this->assertTrue(Hash::needsRehash($old));

        $this->postJson('/api/worker/login', $this->loginPayload())->assertOk();

        $rehashed = CurrentCompany::runAs($this->company->id, fn () => $worker->fresh()->password);
        $this->assertNotSame($old, $rehashed);
        $this->assertFalse(Hash::needsRehash($rehashed));
        $this->assertTrue(Hash::check('secret-pass', $rehashed));

        // 失敗では作り直さない
        DB::table('workers')->where('id', $worker->id)->update(['password' => $old]);
        $this->postJson('/api/worker/login', $this->loginPayload(['password' => 'wrong-pass']))->assertUnprocessable();
        $this->assertSame($old, DB::table('workers')->where('id', $worker->id)->value('password'));
    }

    public function test_ログインの入力は255文字までで文字列でないと422(): void
    {
        Sleep::fake();
        Context::forget('company_id');

        $this->postJson('/api/worker/login', $this->loginPayload([
            'company_code' => str_repeat('A', 256),
            'email' => str_repeat('a', 244).'@example.com',
            'password' => str_repeat('p', 256),
            'device_name' => str_repeat('d', 256),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company_code', 'email', 'password', 'device_name']);

        $this->postJson('/api/worker/login', $this->loginPayload(['company_code' => ['SMPK23'], 'email' => ['taro@example.com']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company_code', 'email']);

        // 検証で弾いたものはサービスまで届かない（Timebox で待たない）
        Sleep::assertNeverSlept();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * 次のログインで、パスワードの照合（Hash::check）がちょうど 1 回、入力のパスワードと本物の形のハッシュで行われることを期待する。
     * ワーカーが見つからない・未登録でも照合を飛ばさない（飛ばすと応答が速くなり、アカウントの有無が時間で分かる）ことを、時間を測らずに確かめる。
     * 照合そのものは本物のハッシャーに任せる（Mockery の proxied partial。ほかのメソッドも本物へ）。
     * 1 つのテストで何度呼んでも、包むのは最初の本物のハッシャー（モックをモックで包まない）。期待はモックごとに検証される
     */
    private function expectOnePasswordCheck(string $password): void
    {
        $real = $this->realHasher ??= $this->app->make('hash');
        $mock = Mockery::mock($real);
        $mock->shouldReceive('check')
            ->once()
            ->withArgs(fn ($value, $hashed) => $value === $password && is_string($hashed) && password_get_info($hashed)['algoName'] !== 'unknown')
            ->andReturnUsing(fn (...$args) => $real->check(...$args));
        Hash::swap($mock);
    }

    /**
     * ログインのテストの準備: Timebox の待ちを Sleep::fake() で止め、自社を「株式会社サンプル / SMPK23」にし、
     * taro@example.com（山田 太郎）のワーカーを作って、企業が空の状態（本番のリクエストの始まり）にする。
     * 登録済みならパスワードは secret-pass。registered: false なら未登録（招待だけ）のワーカー
     *
     * @param  array<string, mixed>  $attributes
     */
    private function prepareLogin(array $attributes = [], bool $registered = true): Worker
    {
        Sleep::fake();
        $this->company->forceFill(['name' => '株式会社サンプル', 'code' => 'SMPK23'])->save();
        $email = $attributes['email'] ?? 'taro@example.com';
        $worker = $registered
            ? $this->registeredWorker(['name' => '山田 太郎', 'email' => $email, 'password' => 'secret-pass'] + $attributes)
            : $this->invitedWorker($email, attributes: $attributes);
        Context::forget('company_id');

        return $worker;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function loginPayload(array $overrides = []): array
    {
        return array_merge([
            'company_code' => 'SMPK23',
            'email' => 'taro@example.com',
            'password' => 'secret-pass',
            'device_name' => 'iPhone 16',
        ], $overrides);
    }

    /**
     * 初回登録が失敗し、何も残っていない（招待は使えるまま・未登録・トークンなし）
     */
    private function assertRegisterRolledBack(Worker $worker): void
    {
        [$worker, $invitation] = CurrentCompany::runAs($this->company->id, fn () => [$worker->fresh(), $worker->invitations()->sole()]);
        $this->assertNull($worker->registered_at);
        $this->assertNull($worker->login_email);
        $this->assertTrue($invitation->isUsable());
        $this->assertSame(self::CODE, $invitation->code);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * 認証の中に置くワーカー用 API（docs/01 の 6.11。register・login 以外）
     *
     * @return list<array{string, string}>
     */
    private static function protectedWorkerRoutes(): array
    {
        return [
            ['POST', '/api/worker/logout'], ['GET', '/api/worker/me'],
            ['GET', '/api/worker/job-postings'], ['GET', '/api/worker/job-postings/{job_posting}'],
            ['POST', '/api/worker/job-postings/{job_posting}/applications'],
            ['GET', '/api/worker/locations'], ['GET', '/api/worker/applications'],
        ];
    }

    /**
     * 未登録のワーカーと、そのワーカーへの使える招待（コードは明示）を今の企業に作る
     *
     * @param  array<string, mixed>  $attributes
     */
    private function invitedWorker(string $email, string $code = self::CODE, array $attributes = []): Worker
    {
        $worker = Worker::factory()->create(['name' => '山田 太郎', 'email' => $email, 'phone' => null] + $attributes);
        WorkerInvitation::factory()->create(['worker_id' => $worker->id, 'code' => $code]);

        return $worker;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'taro@example.com',
            'code' => self::CODE,
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'device_name' => 'iPhone 16',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function registeredWorker(array $attributes = []): Worker
    {
        return Worker::factory()->registered()->create($attributes);
    }
}
