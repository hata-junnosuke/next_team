<?php

namespace App\Http\Requests;

/**
 * F-23 の出力の依頼（POST /api/report-exports、docs/01 6.9）。入力は month（YYYY-MM）だけで、規則・文言・項目名は
 * 月次集計（MonthlyReportRequest）と同じなので継承して 1 箇所にする。生成中の二重依頼は業務ルールなので
 * ここではなく ReportExportService::request() が 422 にする。
 */
class StoreReportExportRequest extends MonthlyReportRequest {}
