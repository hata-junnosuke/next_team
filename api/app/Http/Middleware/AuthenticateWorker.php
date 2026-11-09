<?php

namespace App\Http\Middleware;

use App\Models\Worker;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ワーカーの API の門番（別名 worker。auth:worker の後ろに置く。docs/01 の 5.3）。
 * 管理者の SetCurrentCompany（別名 company）と同じく、通したワーカーの企業を CurrentCompany に入れる。
 * Rails で言えば ApplicationController の before_action :authenticate_worker! と Current.company の設定に当たる。
 *
 * 401 にするもの:
 * - guard worker が返したのが Worker でない（stateful なリクエストでは Sanctum が web ガードの管理者（User）を必ず返すので、
 *   ここが唯一の守り。13 章の 9）
 * - トークンが ability worker を持たない
 * - 初回登録を済ませていない（registered_at が NULL）
 * - 無効にされた（is_active が false）。このときだけ「利用できない」旨の文言を返す（アプリが案内を出せるように）
 *
 * https://readouble.com/laravel/13.x/ja/middleware.html
 */
class AuthenticateWorker
{
    public const DISABLED_MESSAGE = 'このアカウントは現在利用できません。管理者にお問い合わせください。';

    public function handle(Request $request, Closure $next): Response
    {
        $worker = $request->user('worker');

        if (! $worker instanceof Worker || ! $worker->tokenCan('worker') || $worker->registered_at === null) {
            throw new AuthenticationException;
        }

        if (! $worker->is_active) {
            return response()->json(['message' => self::DISABLED_MESSAGE, 'errors' => (object) []], 401);
        }

        CurrentCompany::set($worker->company_id);

        return $next($request);
    }
}
