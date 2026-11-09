<?php

namespace App\Http\Controllers\Worker\Concerns;

use App\Models\Worker;
use Illuminate\Http\Request;

/**
 * ワーカーの API のコントローラが、ログイン中のワーカーを型付きで受け取るための共通処理。
 * auth:worker + worker ミドルウェアの後ろでだけ使う（worker ミドルウェアが Worker であること・有効であることを確かめ済み）。
 * Rails で言えば ApplicationController の current_worker に当たる。
 */
trait ResolvesWorker
{
    protected function worker(Request $request): Worker
    {
        /** @var Worker $worker */
        $worker = $request->user('worker');

        return $worker;
    }
}
