<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Application;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;

/**
 * 勤怠・報酬（F-15、F-16、docs/01 の API 契約（勤怠・報酬））。
 * 他社の応募 ID は Application のグローバルスコープでルートモデルバインディングが見つけられず 404 になる。
 */
class AttendanceController extends Controller
{
    // PUT /api/applications/{application}/attendance（clock_in・clock_out は HH:MM、break_minutes）→ 作成でも更新でも 200
    public function update(SaveAttendanceRequest $request, Application $application, AttendanceService $service): JsonResponse
    {
        $attendance = $service->save(
            $application,
            $request->string('clock_in')->toString(),
            $request->string('clock_out')->toString(),
            $request->integer('break_minutes'),
        );

        // JsonResource は作ったばかりのモデルだと 201 を返すので、契約（作成でも更新でも 200）に合わせて固定する
        return (new AttendanceResource($attendance))->response()->setStatusCode(200);
    }
}
