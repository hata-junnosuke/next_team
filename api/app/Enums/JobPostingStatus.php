<?php

namespace App\Enums;

/**
 * 募集の状態（F-07）。遷移表はこの canTransitionTo() の 1 箇所だけに置く（docs/01 の Enum）。
 *
 *   open   → closed / cancelled
 *   closed → cancelled
 *   cancelled → （終端）
 *
 * 締切（closed）は新規応募の受付停止で、締切後も応募中の応募は承認できる。中止（cancelled）は編集もできない。
 */
enum JobPostingStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => '募集中',
            self::Closed => '締切',
            self::Cancelled => '中止',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Open => [self::Closed, self::Cancelled],
            self::Closed => [self::Cancelled],
            self::Cancelled => [],
        }, true);
    }

    /**
     * 編集できるか（F-05）。中止した募集だけは編集できない。締切は受付を止めただけなので編集できる
     */
    public function isEditable(): bool
    {
        return $this !== self::Cancelled;
    }

    /**
     * 新規の応募を受け付けるか（F-09）。募集中だけ。締切・中止には応募できない
     */
    public function acceptsApplications(): bool
    {
        return $this === self::Open;
    }
}
