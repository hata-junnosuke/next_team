<?php

namespace Tests\Unit;

use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\TestCase;
use LogicException;
use RuntimeException;

/**
 * CurrentCompany は Context ファサードを使うのでアプリの起動が要る。
 * ただし企業を設定済みにする Tests\TestCase ではなく、素の TestCase を使う（未設定の状態を検証するため）。
 */
class CurrentCompanyTest extends TestCase
{
    public function test_未設定でidを呼ぶとlogic_exception(): void
    {
        $this->assertFalse(CurrentCompany::check());

        $this->expectException(LogicException::class);

        CurrentCompany::id();
    }

    public function test_run_asは終了後に元の企業へ戻す(): void
    {
        CurrentCompany::set(1);

        $inside = CurrentCompany::runAs(2, fn () => CurrentCompany::id());

        $this->assertSame(2, $inside);
        $this->assertSame(1, CurrentCompany::id());
    }

    public function test_run_asは例外が起きても元の企業へ戻す(): void
    {
        CurrentCompany::set(1);

        try {
            CurrentCompany::runAs(2, fn () => throw new RuntimeException('失敗'));
        } catch (RuntimeException) {
            // 例外はそのまま呼び出し元へ伝わる
        }

        $this->assertSame(1, CurrentCompany::id());
    }

    public function test_未設定からrun_asしたら終了後は未設定に戻る(): void
    {
        CurrentCompany::runAs(2, fn () => null);

        $this->assertFalse(CurrentCompany::check());
    }
}
