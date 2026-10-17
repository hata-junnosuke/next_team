<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Telescope\Telescope;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * スケジューラ（Rails の whenever / cron の設定に当たる）。動かすには cron で毎分 `php artisan schedule:run` を呼ぶか、
 * 開発中は `php artisan schedule:work` を起動しておく。
 * https://readouble.com/laravel/13.x/ja/scheduling.html
 *
 * F-22 前日リマインド: 毎日 config('myteam.reminder_time')（既定 18:00、Asia/Tokyo）に翌日の勤務分のジョブを積む。
 * withoutOverlapping(30): 前回の実行がまだ終わっていなければ今回は始めない（「2 回送らない」の 1 段目）。
 *   引数はロックを持つ分数。既定は 1440 分（24 時間）で、実行中にプロセスが落ちてロックが残ると翌日の 18 時の実行まで止まり、
 *   その日のリマインドが届かない。コマンドは積むだけで数秒で終わるので、30 分あれば重なりは防げて、落ちたときも翌日には外れている
 */
Schedule::command('app:send-reminders')
    ->dailyAt(config('myteam.reminder_time'))
    ->withoutOverlapping(30);

/*
 * Telescope の記録（telescope_entries）を毎日掃除する。既定では 24 時間より古いものを消す。
 * Telescope はローカル環境でしか登録しない（AppServiceProvider::register()）ので、testing・本番ではコマンドが無い。
 * そこで「パッケージが入っていて、かつローカル」のときだけ登録する（無いコマンドを積むと schedule:run で落ちる）。
 * https://readouble.com/laravel/13.x/ja/telescope.html#data-pruning
 */
if (app()->environment('local') && class_exists(Telescope::class)) {
    Schedule::command('telescope:prune')->daily();
}
