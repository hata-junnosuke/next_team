<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Worker;
use App\Support\CurrentCompany;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
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

        // 自社の募集中・今日以降の募集はバインディングを通る（T3 では 501、T11 で詳細を実装したら 200）
        $status = $this->withToken($token)->getJson("/api/worker/job-postings/{$ownPosting->id}")->status();
        $this->assertContains($status, [200, 501], "自社の募集がバインディングを通らない（{$status}）");
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
     * @param  array<string, mixed>  $attributes
     */
    private function registeredWorker(array $attributes = []): Worker
    {
        return Worker::factory()->registered()->create($attributes);
    }
}
