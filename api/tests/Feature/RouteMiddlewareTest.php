<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Context;
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

    /**
     * docs/01 6.2〜6.8 の全パス。P2-01 で先に全部登録し、未実装のものは 501 を返す（10.1）。
     * 並列担当（A / B / C）が routes/api.php を触らずに済むようにするための契約の固定。
     */
    public function test_6章の全ルートが登録されている(): void
    {
        $expected = [
            'GET api/locations', 'POST api/locations', 'GET api/locations/{location}',
            'PUT api/locations/{location}', 'DELETE api/locations/{location}',
            'GET api/workers', 'POST api/workers', 'GET api/workers/{worker}', 'PUT api/workers/{worker}',
            'PATCH api/workers/{worker}/active',
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
        ];

        $registered = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn (RoutingRoute $route) => collect($route->methods())
                ->reject(fn (string $method) => $method === 'HEAD')
                ->map(fn (string $method) => "{$method} {$route->uri()}"))
            ->all();

        foreach ($expected as $route) {
            $this->assertContains($route, $registered, "{$route} が登録されていない");
        }

        // ワーカーは無効化、募集は中止で終える（要件 7 章。物理削除は使われていない拠点だけ）
        foreach (['DELETE api/workers/{worker}', 'DELETE api/job-postings/{job_posting}'] as $route) {
            $this->assertNotContains($route, $registered, "{$route} は登録してはいけない");
        }
    }

    /**
     * 6 章のうち ID を URL に持つルート。{location} などは置き換え用の印（tester 追加）
     *
     * @return list<array{string, string}>
     */
    private static function boundRoutes(): array
    {
        return [
            ['GET', '/api/locations/{location}'], ['PUT', '/api/locations/{location}'], ['DELETE', '/api/locations/{location}'],
            ['GET', '/api/workers/{worker}'], ['PUT', '/api/workers/{worker}'], ['PATCH', '/api/workers/{worker}/active'],
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
     * 担当が中身を実装した後も、FormRequest の検証（422）より先に 404 になることをここで固定する（docs/01 4.2・6.1）
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

    public function test_6章の全ルートは未ログインなら401(): void
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
}
