<?php

namespace App\Enums;

/**
 * 応募の状態（F-14）。遷移表はこの canTransitionTo() の 1 箇所だけに置く（docs/01 の Enum）。
 * Rails の enum + AASM の遷移定義に相当。status を実際に変えるのは Application::transitionTo() だけ。
 *
 *   applied   → confirmed / rejected / withdrawn
 *   confirmed → cancelled
 *   rejected / cancelled / withdrawn → （終端）
 *
 * withdrawn（取り下げ）はワーカー本人だけが応募中から行う（F-34。ワーカー Step 8）。管理者の API には取り下げの操作がない。
 *
 * https://www.php.net/manual/ja/language.enumerations.backed.php
 */
enum ApplicationStatus: string
{
    case Applied = 'applied';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Applied => '応募中',
            self::Confirmed => '確定',
            self::Rejected => '却下',
            self::Cancelled => 'キャンセル',
            self::Withdrawn => '取り下げ',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Applied => [self::Confirmed, self::Rejected, self::Withdrawn],
            self::Confirmed => [self::Cancelled],
            self::Rejected, self::Cancelled, self::Withdrawn => [],
        }, true);
    }
}
