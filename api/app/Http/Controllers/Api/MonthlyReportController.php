<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MonthlyReportRequest;
use App\Http\Resources\MonthlyReportResource;
use App\Services\MonthlyReportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 月次集計（F-17、F-18、docs/01 6.7）。集計と CSV の行は MonthlyReportService が作る。
 */
class MonthlyReportController extends Controller
{
    // GET /api/reports/monthly（month: YYYY-MM。必須）
    public function show(MonthlyReportRequest $request, MonthlyReportService $service): MonthlyReportResource
    {
        return new MonthlyReportResource($service->build($request->string('month')->toString()));
    }

    /**
     * GET /api/reports/monthly/csv（month）→ text/csv（UTF-8 の BOM つき 7 列。F-18 は同期）。
     * streamDownload は Rails の send_data を 1 行ずつ書き出す形に近い。行は cursor() で読むので全件をメモリに載せない。
     * https://readouble.com/laravel/13.x/ja/responses.html#streamed-downloads
     */
    public function csv(MonthlyReportRequest $request, MonthlyReportService $service): StreamedResponse
    {
        $month = $request->string('month')->toString();

        return response()->streamDownload(function () use ($service, $month) {
            $out = fopen('php://output', 'wb');
            // Excel が UTF-8 と判定できるよう先頭に BOM を付ける
            fwrite($out, "\xEF\xBB\xBF");
            // escape: '' は RFC 4180 どおり「" は "" で表す」だけにする（PHP 8.4 で既定値に頼ると非推奨の警告）
            fputcsv($out, MonthlyReportService::CSV_HEADER, escape: '');
            foreach ($service->csvRows($month) as $row) {
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, "monthly-report-{$month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
