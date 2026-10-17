<?php

namespace App\Jobs;

use App\Services\ReportExportService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * F-23 の拠点 1 つ分の CSV を作るジョブ（Bus::batch() の 1 本）。
 * 中身は ReportExportService::buildChunk() を呼ぶだけ。キューは ReportExportService が batch に onQueue('default') で付ける。
 *
 * - コンストラクタは出力 ID と拠点 ID だけ（モデルを渡さない）
 * - 企業は積んだときの Context（CurrentCompany）がワーカーで戻る
 * - 試行 3 回で打ち切ると、バッチが失敗を記録して catch（ReportExportService::markFailed()）を呼ぶ。
 *   failed() でも markFailed() を呼ぶ（catch のコールバックの例外は Laravel が握りつぶすので、もう 1 つの経路を持つ。2 回呼んでも 1 回だけ failed になる）
 * https://readouble.com/laravel/13.x/ja/queues.html#job-batching
 */
class BuildLocationCsvChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $reportExportId,
        public readonly int $locationId,
    ) {}

    /**
     * 1 回目の再試行は 10 秒後、2 回目は 60 秒後（SQLite のロック待ちなど一時的な失敗をやり過ごす）
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ReportExportService $service): void
    {
        // 他のチャンクが失敗してバッチがキャンセルされていたら、もう作らない（出力は failed になっている）
        if ($this->batch()?->cancelled()) {
            return;
        }

        $service->buildChunk($this->reportExportId, $this->locationId);
    }

    /**
     * 打ち切った後に 1 回だけ呼ばれる。failed() から例外を漏らさない（ワーカーの失敗処理を止めない）
     */
    public function failed(?Throwable $e): void
    {
        try {
            app(ReportExportService::class)->markFailed($this->reportExportId);
        } catch (Throwable $recordError) {
            report($recordError);
        }
    }
}
