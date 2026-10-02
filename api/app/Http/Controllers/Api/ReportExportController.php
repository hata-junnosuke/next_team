<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportExportRequest;
use App\Http\Resources\ReportExportResource;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F-23 月次集計の非同期出力（docs/01 6.9）。受付・一覧・詳細は auth:sanctum + company の中、ダウンロードだけ署名付き URL。
 * https://readouble.com/laravel/13.x/ja/queues.html#job-batching
 */
class ReportExportController extends Controller
{
    /** 一覧の件数（docs/01 6.9: 新しい順に最大 20 件） */
    private const INDEX_LIMIT = 20;

    public function index(ReportExportService $service): AnonymousResourceCollection
    {
        return ReportExportResource::collection(
            $service->query()
                ->orderByDesc('report_exports.created_at')
                ->orderByDesc('report_exports.id')
                ->limit(self::INDEX_LIMIT)
                ->get()
        );
    }

    /**
     * 202 Accepted: 受け付けただけで、出力はキューのワーカーが作る。画面は返った id で詳細を取り直して進捗を見る
     */
    public function store(StoreReportExportRequest $request, ReportExportService $service): JsonResponse
    {
        $export = $service->request($request->user(), $request->string('month')->toString());

        return (new ReportExportResource($export))->response()->setStatusCode(202);
    }

    /**
     * 他社・存在しない ID は findOrFail が 404（グローバルスコープで自社の行しか見えない）
     */
    public function show(string $reportExport, ReportExportService $service): ReportExportResource
    {
        return new ReportExportResource($service->query()->findOrFail($reportExport));
    }

    /**
     * GET /api/report-exports/{id}/download?expires=…&signature=…（auth:sanctum の外。signed ミドルウェアが期限切れ・改ざんを 403 にする）。
     * どのファイルを渡してよいかの判定（スコープを外した読み出しを含む）は ReportExportService::downloadablePath()。
     * 給与を含む CSV なので、ブラウザや途中のプロキシに残させない（no-store, private）
     */
    public function download(int $reportExport, ReportExportService $service): StreamedResponse
    {
        $path = $service->downloadablePath($reportExport);

        // ファイル名は保存名（monthly-{month}.csv）。企業 ID と出力 ID はディレクトリ側にあり、ファイル名には出ない
        return Storage::disk('local')->download($path, basename($path), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
