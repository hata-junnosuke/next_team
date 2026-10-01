<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * ログイン中の管理者と所属企業（画面のヘッダに出す）。
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user()->load('company'));
    }
}
