<?php

namespace App\Enums;

/**
 * 集計出力の状態（F-23。report_exports.status）。遷移表はこの canTransitionTo() の 1 箇所だけに置く。
 *
 *   queued     → processing / completed / failed（拠点が 0 件ならバッチを作らずに直接 completed）
 *   processing → completed / failed
 *   completed / failed → （終端。作り直すときは新しい依頼にする）
 */
enum ReportExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => '受付',
            self::Processing => '生成中',
            self::Completed => '完了',
            self::Failed => '失敗',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Queued => [self::Processing, self::Completed, self::Failed],
            self::Processing => [self::Completed, self::Failed],
            self::Completed, self::Failed => [],
        }, true);
    }

    /**
     * まだ生成中か（同じ月の二重依頼を 422 にする判定に使う。F-23）
     */
    public function isInProgress(): bool
    {
        return in_array($this, [self::Queued, self::Processing], true);
    }
}
