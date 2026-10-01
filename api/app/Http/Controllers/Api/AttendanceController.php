<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 勤怠・報酬（F-15、F-16、docs/01 6.6）。担当 C（P2-05）が実装する。今は 501。
 */
class AttendanceController extends Controller
{
    // PUT /api/applications/{application}/attendance（clock_in・clock_out は HH:MM、break_minutes）→ 作成でも更新でも 200
    public function update(Request $request, Application $application): JsonResponse
    {
        return $this->notImplemented();
    }
}
