<?php

namespace App\Services;

/**
 * 報酬計算の結果（F-16）。PayrollCalculator::calculate() が返す値オブジェクト。
 * readonly class なので作った後に書き換えられない（Rails なら Data.define に近い）。
 * https://www.php.net/manual/ja/language.oop5.basic.php#language.oop5.basic.class.readonly
 */
final readonly class PayrollResult
{
    public function __construct(
        public int $workedMinutes,
        /** 賃金（交通費を含まない） */
        public int $wagePay,
        /** 合計（賃金 + 交通費） */
        public int $totalPay,
    ) {}
}
