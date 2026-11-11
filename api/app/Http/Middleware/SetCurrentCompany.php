<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン中の管理者の企業を CurrentCompany に入れる（別名 company。auth:sanctum の後ろに置く）。
 * 以降のクエリ・ジョブはこの企業として動く。
 * ワーカーの API は別のミドルウェア AuthenticateWorker（別名 worker）が同じ役目をする。
 */
class SetCurrentCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // auth:sanctum の後ろに置く前提。万一ログインしていなければ 401 にする（企業なしで先へ進ませない）。
        // 管理者（User）でなければ、ワーカーなどが guard を通っていても 401 にする（guard sanctum の provider と合わせた 2 重の守り。
        // docs/01 の 5.3・13 章の 7）
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        CurrentCompany::set($user->company_id);

        return $next($request);
    }
}
