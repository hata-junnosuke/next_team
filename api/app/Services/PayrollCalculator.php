<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * 報酬計算（F-16、docs/01 の業務ルールの決定）。DB にも時刻にも触らない純粋な計算だけを置く。
 * 時給・交通費は応募の確定時の値（confirmed_*）を呼び出し側が渡す。
 *
 * 賃金 = intdiv(労働分 × 時給 + 59, 60)。分単位の賃金を円未満切り上げにするため、
 * 浮動小数点（ceil(分 × 時給 / 60)）を使わず整数だけで計算する（誤差で 1 円ずれない）。
 * https://www.php.net/manual/ja/function.intdiv.php
 */
class PayrollCalculator
{
    public function calculate(int $workedMinutes, int $hourlyWage, int $transport): PayrollResult
    {
        if ($workedMinutes < 0 || $hourlyWage < 0 || $transport < 0) {
            // 入力の検証は AttendanceService が済ませている。負の値が来るのは呼び出し側のバグ
            throw new InvalidArgumentException('労働分・時給・交通費は 0 以上で渡してください。');
        }

        $wagePay = intdiv($workedMinutes * $hourlyWage + 59, 60);

        return new PayrollResult(
            workedMinutes: $workedMinutes,
            wagePay: $wagePay,
            totalPay: $wagePay + $transport,
        );
    }
}
