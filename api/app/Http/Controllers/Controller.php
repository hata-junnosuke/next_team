<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    /**
     * 一覧の 1 ページの件数（docs/01 6.1）。?per_page= を 1〜100 に丸める。未指定なら 20。
     */
    protected function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);
    }

    /**
     * P2-01 で全ルートを先に登録したときの、未実装のアクションの応答（docs/01 10.1）。
     * 担当（A / B / C）が実装したら呼び出しを消す。全部消えたらこのメソッドも消す
     */
    protected function notImplemented(): JsonResponse
    {
        return response()->json(['message' => 'まだ実装されていません。', 'errors' => (object) []], 501);
    }
}
