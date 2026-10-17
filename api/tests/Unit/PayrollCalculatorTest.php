<?php

namespace Tests\Unit;

use App\Services\PayrollCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-16 報酬計算（docs/01 の業務ルールの決定、必須テスト）。
 * 賃金 = intdiv(労働分 × 時給 + 59, 60)（円未満切り上げ）、合計 = 賃金 + 交通費。
 * DB を使わない純粋な計算なので PHPUnit の TestCase を直接使う。
 */
class PayrollCalculatorTest extends TestCase
{
    public function test_7時間で時給1300円交通費500円なら9600円(): void
    {
        $result = (new PayrollCalculator)->calculate(420, 1300, 500);

        $this->assertSame(420, $result->workedMinutes);
        $this->assertSame(9100, $result->wagePay);
        $this->assertSame(9600, $result->totalPay);
    }

    public function test_円未満は切り上げる(): void
    {
        // 1 分 × 1,000 円 / 60 = 16.66… → 17 円
        $this->assertSame(17, (new PayrollCalculator)->calculate(1, 1000, 0)->wagePay);
        // 割り切れるときは切り上げない（61 分 × 1,200 円 / 60 = 1,220 円ちょうど）
        $this->assertSame(1220, (new PayrollCalculator)->calculate(61, 1200, 0)->wagePay);
        // 7 分 × 1,111 円 / 60 = 129.61… → 130 円、合計は交通費を足す
        $result = (new PayrollCalculator)->calculate(7, 1111, 300);
        $this->assertSame(130, $result->wagePay);
        $this->assertSame(430, $result->totalPay);
    }

    public function test_労働0分なら交通費のみ(): void
    {
        $result = (new PayrollCalculator)->calculate(0, 1300, 500);

        $this->assertSame(0, $result->workedMinutes);
        $this->assertSame(0, $result->wagePay);
        $this->assertSame(500, $result->totalPay);
    }

    /**
     * 円未満切り上げの境界。分 × 時給 を 60 で割った余りが 0 / 1 / 59 のとき
     *
     * @return array<string, array{int, int, int}>
     */
    public static function roundingBoundaries(): array
    {
        return [
            // 60 × 1,000 = 60,000 → 1,000 円ちょうど（余り 0 は切り上げない）
            '余り0' => [60, 1000, 1000],
            // 1 × 61 = 61 → 1.016… → 2 円（余り 1 でも切り上げる）
            '余り1' => [1, 61, 2],
            // 1 × 59 = 59 → 0.98… → 1 円（余り 59）
            '余り59' => [1, 59, 1],
            // 1 × 1 = 1 → 0.016… → 1 円（最小の正の値）
            '最小' => [1, 1, 1],
            // 59 × 1,061 = 62,599 → 1,043.31… → 1,044 円
            '1時間未満' => [59, 1061, 1044],
        ];
    }

    #[DataProvider('roundingBoundaries')]
    public function test_円未満切り上げの境界(int $minutes, int $wage, int $expected): void
    {
        $this->assertSame($expected, (new PayrollCalculator)->calculate($minutes, $wage, 0)->wagePay);
    }

    public function test_浮動小数点の誤差で1円ずれない(): void
    {
        // ceil(1,439 × 1,015 / 60) を float で計算すると誤差が出うる組み合わせでも、整数の式と一致する
        foreach ([[1439, 1015], [7, 1110], [33, 1020], [1, 1200], [480, 1163]] as [$minutes, $wage]) {
            $expected = intdiv($minutes * $wage, 60) + (($minutes * $wage) % 60 === 0 ? 0 : 1);
            $this->assertSame($expected, (new PayrollCalculator)->calculate($minutes, $wage, 0)->wagePay, "{$minutes} 分 × {$wage} 円");
        }
    }

    public function test_時給0円なら交通費のみ(): void
    {
        $result = (new PayrollCalculator)->calculate(480, 0, 700);

        $this->assertSame(0, $result->wagePay);
        $this->assertSame(700, $result->totalPay);
    }

    public function test_交通費0円なら合計は賃金と同じ(): void
    {
        $result = (new PayrollCalculator)->calculate(420, 1300, 0);

        $this->assertSame(9100, $result->wagePay);
        $this->assertSame(9100, $result->totalPay);
    }

    public function test_1日上限の1439分でも整数で計算できる(): void
    {
        // 1,439 × 99,999 = 143,898,561 → 2,398,309.35 → 2,398,310 円
        $result = (new PayrollCalculator)->calculate(1439, 99999, 1000);

        $this->assertSame(2398310, $result->wagePay);
        $this->assertSame(2399310, $result->totalPay);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function negativeInputs(): array
    {
        return [
            '労働分が負' => [-1, 1300, 500],
            '時給が負' => [60, -1, 500],
            '交通費が負' => [60, 1300, -1],
        ];
    }

    #[DataProvider('negativeInputs')]
    public function test_負の値は_invalid_argument_exception(int $minutes, int $wage, int $transport): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PayrollCalculator)->calculate($minutes, $wage, $transport);
    }
}
