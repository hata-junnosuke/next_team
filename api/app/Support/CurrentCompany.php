<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Context;
use LogicException;

/**
 * 「今どの企業として動いているか」を 1 箇所で持つ（Rails の Current.company に相当）。
 *
 * 中身は Context ファサード。Context に入れた値はキューに積んだジョブへ自動で引き継がれるので、
 * リクエスト中に積んだジョブも同じ企業として動く。
 * https://readouble.com/laravel/13.x/ja/context.html
 *
 * 設定してよい場所: SetCurrentCompany ミドルウェア / スケジューラのコマンド（runAs）/ テスト。
 */
final class CurrentCompany
{
    private const KEY = 'company_id';

    public static function set(int $companyId): void
    {
        Context::add(self::KEY, $companyId);
    }

    /**
     * 未設定なら例外にする。黙って null を返すと、グローバルスコープが外れて全社のデータが見えてしまうため。
     */
    public static function id(): int
    {
        if (! self::check()) {
            throw new LogicException('企業が設定されていません（CurrentCompany::set() / runAs() を通していない）。');
        }

        return (int) Context::get(self::KEY);
    }

    public static function check(): bool
    {
        return Context::has(self::KEY);
    }

    /**
     * 一時的に別の企業として $callback を実行し、終わったら（例外でも）元の状態に戻す。
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function runAs(int $companyId, Closure $callback): mixed
    {
        $hadPrevious = self::check();
        $previous = $hadPrevious ? self::id() : null;

        self::set($companyId);

        try {
            return $callback();
        } finally {
            if ($hadPrevious) {
                self::set($previous);
            } else {
                Context::forget(self::KEY);
            }
        }
    }
}
