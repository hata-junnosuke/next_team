<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\JobPostingController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MonthlyReportController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkerController;
use Illuminate\Support\Facades\Route;

/*
 * /api/* はすべて auth:sanctum（ログイン必須）+ company（CurrentCompany の設定）の中に置く。
 * 例外は署名付きURL のダウンロードだけ（P4。signed ミドルウェア必須）。
 *
 * docs/01 6 章の全ルートを P2-01 で先に登録する（10.1）。未実装のアクションは 501 を返し、
 * 並列担当（A / B / C）は自分のコントローラの中身だけを書けばよい（このファイルは触らない）。
 * Rails の routes.rb の resources + member ルートに相当。
 * https://readouble.com/laravel/13.x/ja/controllers.html#api-resource-routes
 */
Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('/user', [UserController::class, 'show'])->name('user.show');

    // F-01 拠点（担当 A）: locations.index / store / show / update / destroy
    Route::apiResource('locations', LocationController::class);

    // F-02〜F-04 ワーカー（担当 A）。削除はしない（無効化）
    Route::apiResource('workers', WorkerController::class)->except(['destroy']);
    Route::patch('/workers/{worker}/active', [WorkerController::class, 'toggleActive'])->name('workers.active');

    // F-05〜F-08 募集（担当 B）。削除はしない（中止）。パラメータ名は {job_posting}
    Route::apiResource('job-postings', JobPostingController::class)->except(['destroy']);
    Route::prefix('job-postings/{job_posting}')->name('job-postings.')->group(function () {
        Route::post('/close', [JobPostingController::class, 'close'])->name('close');
        Route::post('/cancel', [JobPostingController::class, 'cancel'])->name('cancel');
        Route::post('/duplicate', [JobPostingController::class, 'duplicate'])->name('duplicate');

        // F-09 応募の代行入力（担当 B）
        Route::get('/applicable-workers', [ApplicationController::class, 'applicableWorkers'])->name('applicable-workers');
        Route::get('/applications', [ApplicationController::class, 'index'])->name('applications.index');
        Route::post('/applications', [ApplicationController::class, 'store'])->name('applications.store');
    });

    Route::prefix('applications/{application}')->name('applications.')->group(function () {
        // F-11〜F-14 承認・却下・キャンセル（担当 B）
        Route::post('/confirm', [ApplicationController::class, 'confirm'])->name('confirm');
        Route::post('/reject', [ApplicationController::class, 'reject'])->name('reject');
        Route::post('/cancel', [ApplicationController::class, 'cancel'])->name('cancel');

        // F-15、F-16 勤怠（担当 C）。作成でも更新でも PUT
        Route::put('/attendance', [AttendanceController::class, 'update'])->name('attendance.update');
    });

    // F-17、F-18 月次集計（担当 C）
    Route::get('/reports/monthly', [MonthlyReportController::class, 'show'])->name('reports.monthly');
    Route::get('/reports/monthly/csv', [MonthlyReportController::class, 'csv'])->name('reports.monthly.csv');

    // F-19 ダッシュボード（担当 C）
    Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard');
});
