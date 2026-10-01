<?php

namespace App\Enums;

/**
 * 確定のキャンセル理由（F-13）。キャンセルするときは必須。募集の中止による自動キャンセルは Company（企業都合）。
 */
enum CancelReason: string
{
    case Company = 'company';
    case Worker = 'worker';

    public function label(): string
    {
        return match ($this) {
            self::Company => '企業都合',
            self::Worker => 'ワーカー都合',
        };
    }
}
