<?php

namespace App\Http\Resources;

use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * 集計出力の JSON（docs/01 6.9）: {id, month, status: {value, label}, progress, row_count, requested_at, completed_at, download_url}
 *
 * - progress: 0〜100。completed は 100、それ以外は job_batches の進捗（(全ジョブ - 残り) / 全ジョブ。Batch::progress() と同じ式）。
 *   バッチの列は ReportExportService::query() の left join で読んだ値（Resource でクエリを発行しない）。なければ 0
 * - download_url: completed のときだけ。取得のたびに 30 分有効の署名付き URL を新しく作る（URL を持っていること自体が認可。
 *   署名は APP_KEY で作るので、ID や期限を書き換えると合わなくなる）。他社の出力はグローバルスコープで読めないので、
 *   他社の ID の URL はここで作られない
 * https://readouble.com/laravel/13.x/ja/urls.html#signed-urls
 *
 * @mixin ReportExport
 */
class ReportExportResource extends JsonResource
{
    /** 署名付き URL の有効期限（分。docs/01 6.9） */
    public const DOWNLOAD_URL_MINUTES = 30;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $completed = $this->status === ReportExportStatus::Completed;

        return [
            'id' => $this->id,
            'month' => $this->month,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'progress' => $completed ? 100 : $this->batchProgress(),
            'row_count' => $this->row_count,
            'requested_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'download_url' => $completed
                ? URL::temporarySignedRoute('report-exports.download', now()->addMinutes(self::DOWNLOAD_URL_MINUTES), ['report_export' => $this->id])
                : null,
        ];
    }

    private function batchProgress(): int
    {
        $total = (int) $this->resource->getAttribute('batch_total_jobs');
        $pending = (int) $this->resource->getAttribute('batch_pending_jobs');

        return $total > 0 ? (int) round(($total - $pending) / $total * 100) : 0;
    }
}
