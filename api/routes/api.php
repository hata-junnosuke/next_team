<?php

use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
 * /api/* はすべて auth:sanctum（ログイン必須）+ company（CurrentCompany の設定）の中に置く。
 * 例外は署名付きURL のダウンロードだけ（P4。signed ミドルウェア必須）。
 */
Route::middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('/user', [UserController::class, 'show'])->name('user.show');
});
