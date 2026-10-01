<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

/*
 * api は JSON 専用なので画面のルートは置かない。
 *
 * Fortify のルートは FortifyServiceProvider で ignoreRoutes() にしてあり、ここで必要な 2 本だけを登録する。
 * ミドルウェアと名前は vendor/laravel/fortify/routes/routes.php と同じ（web グループ = セッション + CSRF）。
 * sanctum/csrf-cookie は Sanctum が登録する。
 */
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware(['guest:web', 'throttle:login'])
    ->name('login.store');

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware(['auth:web'])
    ->name('logout');
