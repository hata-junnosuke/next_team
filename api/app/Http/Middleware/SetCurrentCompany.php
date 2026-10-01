<?php

namespace App\Http\Middleware;

use App\Support\CurrentCompany;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン中の管理者の企業を CurrentCompany に入れる（別名 company。auth:sanctum の後ろに置く）。
 * 以降のクエリ・ジョブはこの企業として動く。
 */
class SetCurrentCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // auth:sanctum の後ろに置く前提。万一ログインしていなければ 401 にする（企業なしで先へ進ませない）
        if ($user === null) {
            throw new AuthenticationException;
        }

        CurrentCompany::set($user->company_id);

        return $next($request);
    }
}
