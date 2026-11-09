<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\LoginRequest;
use App\Http\Requests\Worker\RegisterRequest;
use App\Http\Resources\Worker\WorkerMeResource;
use App\Models\Worker;
use App\Services\WorkerAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ワーカーの認証（F-28・F-29。docs/01 の 5.3・6.11）。register と login は認証の外（throttle 付き）、me と logout は auth:worker + worker の中。
 * https://readouble.com/laravel/13.x/ja/sanctum.html#mobile-application-authentication
 */
class AuthController extends Controller
{
    public function __construct(private readonly WorkerAuthService $auth) {}

    /**
     * POST /api/worker/register（F-28）。201 {"data": {"token", "worker": WorkerMeResource}}
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register(
            $request->string('email')->toString(),
            $request->string('code')->toString(),
            $request->string('password')->toString(),
            $request->string('device_name')->toString(),
        );

        return response()->json(['data' => [
            'token' => $result['token'],
            'worker' => new WorkerMeResource($result['worker']),
        ]], 201);
    }

    /**
     * POST /api/worker/login（F-29）。200 {"data": {"token", "worker": WorkerMeResource}}
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->string('company_code')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->string('device_name')->toString(),
        );

        return response()->json(['data' => [
            'token' => $result['token'],
            'worker' => new WorkerMeResource($result['worker']),
        ]]);
    }

    /**
     * POST /api/worker/logout。今のリクエストのトークンだけを消す（ほかの端末のトークンはそのまま。5.3）
     */
    public function logout(Request $request): Response
    {
        $this->auth->logout($this->worker($request));

        return response()->noContent();
    }

    /**
     * GET /api/worker/me
     */
    public function me(Request $request): WorkerMeResource
    {
        return new WorkerMeResource($this->worker($request)->load('company'));
    }

    /**
     * worker ミドルウェアが Worker であることを確かめた後なので、ここでは型を絞るだけ
     */
    private function worker(Request $request): Worker
    {
        /** @var Worker $worker */
        $worker = $request->user('worker');

        return $worker;
    }
}
