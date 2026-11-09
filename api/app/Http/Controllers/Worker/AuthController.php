<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Resources\Worker\WorkerMeResource;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ワーカーの認証（F-28・F-29。docs/01 の 5.3・6.11）。register と login は認証の外（throttle 付き）、me と logout は auth:worker + worker の中。
 * https://readouble.com/laravel/13.x/ja/sanctum.html#mobile-application-authentication
 */
class AuthController extends Controller
{
    /**
     * POST /api/worker/register（F-28。issue #37 の T9 で実装する）
     */
    public function register(): JsonResponse
    {
        return $this->notImplemented();
    }

    /**
     * POST /api/worker/login（F-29。issue #37 の T10 で実装する）
     */
    public function login(): JsonResponse
    {
        return $this->notImplemented();
    }

    /**
     * POST /api/worker/logout。今のリクエストのトークンだけを消す（ほかの端末のトークンはそのまま。5.3）
     */
    public function logout(Request $request): Response
    {
        $this->worker($request)->currentAccessToken()->delete();

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
