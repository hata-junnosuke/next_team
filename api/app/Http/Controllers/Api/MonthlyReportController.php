<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 月次集計（F-17、F-18、docs/01 6.7）。担当 C（P2-06）が実装する。今は 501。
 */
class MonthlyReportController extends Controller
{
    // GET /api/reports/monthly（month: YYYY-MM。必須）
    public function show(Request $request): JsonResponse
    {
        return $this->notImplemented();
    }

    // GET /api/reports/monthly/csv（month）→ text/csv（BOM つき 7 列）。実装時は戻り値の型を StreamedResponse などに変える
    public function csv(Request $request): JsonResponse
    {
        return $this->notImplemented();
    }
}
