<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Worker;
use App\Support\CurrentCompany;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ActsAsWorker;
use Tests\TestCase;

/**
 * /api/* のルートを全部走査し、auth:sanctum と company の付け忘れを機械的に見つける（docs/01 の API の共通）。
 * 例外は署名付きURL のダウンロード（signed 付き）だけ。
 * ワーカーアプリの /api/worker/* は auth:worker と worker の付け忘れを別に見る（docs/01 の 5.3・6.11）。
 */
class RouteMiddlewareTest extends TestCase
{
    use ActsAsWorker;

    public function test_apiの全ルートにauth_sanctumとcompanyが付いている(): void
    {
        // ワーカー用 API（api/worker/*）は guard が別なので下のテストで見る
        $apiRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/'))
            ->reject(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/worker/'));

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

    public function test_ワーカーのapiの全ルートにauth_workerとworkerが付き登録とログインだけが試行回数の制限つき(): void
    {
        $workerRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/worker/'));

        $this->assertNotEmpty($workerRoutes, 'api/worker/* のルートが 1 本もない');

        // 認証の外に置くのはこの 2 本だけ（docs/01 の 5.3）。総当たりに備えて試行回数を制限する
        $public = [
            'POST api/worker/register' => 'throttle:worker-register',
            'POST api/worker/login' => 'throttle:worker-login',
        ];
        $seenPublic = [];

        foreach ($workerRoutes as $route) {
            $middleware = $route->gatherMiddleware();
            $label = collect($route->methods())->reject(fn (string $method) => $method === 'HEAD')->first().' '.$route->uri();
            $throttles = array_values(array_filter($middleware, fn (string $name) => str_starts_with($name, 'throttle:')));

            if (array_key_exists($label, $public)) {
                $seenPublic[] = $label;
                $this->assertSame([$public[$label]], $throttles, "{$label} の試行回数の制限が違う");
                $this->assertNotContains('auth:worker', $middleware, "{$label} は認証の外に置く");
                $this->assertNotContains('auth:sanctum', $middleware, "{$label} は認証の外に置く");

                continue;
            }

            $this->assertContains('auth:worker', $middleware, "{$label} に auth:worker がない");
            $this->assertContains('worker', $middleware, "{$label} に worker がない");
            $this->assertNotContains('auth:sanctum', $middleware, "{$label} に管理者の auth:sanctum が付いている");
            $this->assertNotContains('company', $middleware, "{$label} に管理者の company が付いている");
            $this->assertSame([], $throttles, "{$label} に試行回数の制限が付いている");
        }

        $this->assertEqualsCanonicalizing(array_keys($public), $seenPublic, '登録とログインのルートがない');
    }

    /**
     * docs/01 の API 契約のうち拠点からダッシュボードまでの全パス。先に全部登録し、未実装のものは 501 を返す（docs/01 の基盤の担当）。
     * 並列担当（A / B / C）が routes/api.php を触らずに済むようにするための契約の固定。
     */
    public function test_api契約の全ルートが登録されている(): void
    {
        $expected = [
            'GET api/locations', 'POST api/locations', 'GET api/locations/{location}',
            'PUT api/locations/{location}', 'DELETE api/locations/{location}',
            'GET api/workers', 'POST api/workers', 'GET api/workers/{worker}', 'PUT api/workers/{worker}',
            'PATCH api/workers/{worker}/active', 'POST api/workers/{worker}/invitation',
            'GET api/job-postings', 'POST api/job-postings', 'GET api/job-postings/{job_posting}',
            'PUT api/job-postings/{job_posting}',
            'POST api/job-postings/{job_posting}/close', 'POST api/job-postings/{job_posting}/cancel',
            'POST api/job-postings/{job_posting}/duplicate', 'GET api/job-postings/{job_posting}/applicable-workers',
            'GET api/job-postings/{job_posting}/applications', 'POST api/job-postings/{job_posting}/applications',
            'POST api/applications/{application}/confirm', 'POST api/applications/{application}/reject',
            'POST api/applications/{application}/cancel',
            'PUT api/applications/{application}/attendance',
            'GET api/reports/monthly', 'GET api/reports/monthly/csv',
            'GET api/dashboard',
            // ワーカー用 API（docs/01 の 6.11）
            'POST api/worker/register', 'POST api/worker/login', 'POST api/worker/logout', 'GET api/worker/me',
            'GET api/worker/job-postings', 'GET api/worker/job-postings/{job_posting}',
            'POST api/worker/job-postings/{job_posting}/applications',
            'GET api/worker/locations', 'GET api/worker/applications',
        ];

        $registered = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn (RoutingRoute $route) => collect($route->methods())
                ->reject(fn (string $method) => $method === 'HEAD')
                ->map(fn (string $method) => "{$method} {$route->uri()}"))
            ->all();

        foreach ($expected as $route) {
            $this->assertContains($route, $registered, "{$route} が登録されていない");
        }

        // ワーカーは無効化、募集は中止で終える（要件の非機能要件。物理削除は使われていない拠点だけ）
        foreach (['DELETE api/workers/{worker}', 'DELETE api/job-postings/{job_posting}'] as $route) {
            $this->assertNotContains($route, $registered, "{$route} は登録してはいけない");
        }
    }

    /**
     * API 契約のうち ID を URL に持つルート。{location} などは置き換え用の印（tester 追加）
     *
     * @return list<array{string, string}>
     */
    private static function boundRoutes(): array
    {
        return [
            ['GET', '/api/locations/{location}'], ['PUT', '/api/locations/{location}'], ['DELETE', '/api/locations/{location}'],
            ['GET', '/api/workers/{worker}'], ['PUT', '/api/workers/{worker}'], ['PATCH', '/api/workers/{worker}/active'],
            ['POST', '/api/workers/{worker}/invitation'],
            ['GET', '/api/job-postings/{job_posting}'], ['PUT', '/api/job-postings/{job_posting}'],
            ['POST', '/api/job-postings/{job_posting}/close'], ['POST', '/api/job-postings/{job_posting}/cancel'],
            ['POST', '/api/job-postings/{job_posting}/duplicate'], ['GET', '/api/job-postings/{job_posting}/applicable-workers'],
            ['GET', '/api/job-postings/{job_posting}/applications'], ['POST', '/api/job-postings/{job_posting}/applications'],
            ['POST', '/api/applications/{application}/confirm'], ['POST', '/api/applications/{application}/reject'],
            ['POST', '/api/applications/{application}/cancel'], ['PUT', '/api/applications/{application}/attendance'],
        ];
    }

    /**
     * @param  array<string, int>  $ids
     */
    private static function fill(string $uri, array $ids): string
    {
        return strtr($uri, array_combine(array_map(fn (string $key) => '{'.$key.'}', array_keys($ids)), $ids));
    }

    /**
     * 未実装（501）のルートも含め、他社の ID はルートモデルバインディング（グローバルスコープ）で 404 になる。
     * 担当が中身を実装した後も、FormRequest の検証（422）より先に 404 になることをここで固定する（docs/01 のテナント分離・API の共通）
     */
    public function test_idを持つ全ルートで他社のidは404(): void
    {
        $other = Company::factory()->create();
        $ids = CurrentCompany::runAs($other->id, function () {
            $attendance = Attendance::factory()->create();
            $application = $attendance->application;

            return [
                'location' => $application->jobPosting->location_id,
                'worker' => $application->worker_id,
                'job_posting' => $application->job_posting_id,
                'application' => $application->id,
            ];
        });
        // 本番のリクエストは企業が空の状態で始まる。setUp の値に助けられないよう消してから叩く
        Context::forget('company_id');

        foreach (self::boundRoutes() as [$method, $uri]) {
            $this->actingAsAdmin()
                ->json($method, self::fill($uri, $ids), ['worker_id' => $ids['worker'], 'reason' => 'worker'])
                ->assertNotFound()
                ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);
        }
    }

    public function test_idを持つ全ルートで存在しないidは404(): void
    {
        $ids = ['location' => 999999, 'worker' => 999999, 'job_posting' => 999999, 'application' => 999999];

        foreach (self::boundRoutes() as [$method, $uri]) {
            $this->actingAsAdmin()->json($method, self::fill($uri, $ids))->assertNotFound();
        }
    }

    public function test_api契約の全ルートは未ログインなら401(): void
    {
        $attendance = Attendance::factory()->create();
        $application = $attendance->application;
        $ids = [
            'location' => $application->jobPosting->location_id,
            'worker' => $application->worker_id,
            'job_posting' => $application->job_posting_id,
            'application' => $application->id,
        ];
        Context::forget('company_id');

        $routes = [
            ...self::boundRoutes(),
            ['GET', '/api/locations'], ['POST', '/api/locations'], ['GET', '/api/workers'], ['POST', '/api/workers'],
            ['GET', '/api/job-postings'], ['POST', '/api/job-postings'],
            ['GET', '/api/reports/monthly'], ['GET', '/api/reports/monthly/csv'], ['GET', '/api/dashboard'],
        ];
        foreach ($routes as [$method, $uri]) {
            $this->json($method, self::fill($uri, $ids))->assertUnauthorized();
        }
    }

    /**
     * ワーカー用 API（docs/01 の 6.11）のうち ID を URL に持つルート
     *
     * @return list<array{string, string}>
     */
    private static function workerBoundRoutes(): array
    {
        return [
            ['GET', '/api/worker/job-postings/{job_posting}'],
            ['POST', '/api/worker/job-postings/{job_posting}/applications'],
        ];
    }

    /**
     * ワーカーの API でも、他社の ID は worker ミドルウェアが決めた企業のグローバルスコープで 404 になる（6.11 の失敗の表）。
     * 本物のトークン（withToken）で叩く（docs/01 の 5.3）。T11・T12 で中身を実装した後も、ここで 404 を固定する
     */
    public function test_ワーカーのidを持つ全ルートで他社のidは404(): void
    {
        $other = Company::factory()->create();
        $otherPosting = CurrentCompany::runAs($other->id, fn () => JobPosting::factory()->create());
        $token = $this->workerToken(Worker::factory()->registered()->create());
        Context::forget('company_id');

        foreach (self::workerBoundRoutes() as [$method, $uri]) {
            $this->withToken($token)
                ->json($method, self::fill($uri, ['job_posting' => $otherPosting->id]))
                ->assertNotFound()
                ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);
            $this->forgetAuthenticatedUsers();
        }

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_ワーカーのidを持つ全ルートで存在しないidは404(): void
    {
        $token = $this->workerToken(Worker::factory()->registered()->create());
        Context::forget('company_id');

        foreach (self::workerBoundRoutes() as [$method, $uri]) {
            $this->withToken($token)->json($method, self::fill($uri, ['job_posting' => 999999]))->assertNotFound();
            $this->forgetAuthenticatedUsers();
        }
    }
}
