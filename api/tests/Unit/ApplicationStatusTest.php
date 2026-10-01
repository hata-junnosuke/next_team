<?php

namespace Tests\Unit;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 状態遷移（F-14、要件 7 章の必須テスト）。遷移表は Enum の canTransitionTo() の 1 箇所にある（docs/01 4.1）。
 * P2-01 では Enum の分だけ。P2-04 で Application::transitionTo() の例外の分を足す。
 *
 * Enum だけを見るのでアプリの起動は要らない（素の PHPUnit の TestCase。Rails の PORO のユニットテストに相当）。
 */
class ApplicationStatusTest extends TestCase
{
    public function test_応募中から確定と却下へ遷移できる(): void
    {
        $this->assertTrue(ApplicationStatus::Applied->canTransitionTo(ApplicationStatus::Confirmed));
        $this->assertTrue(ApplicationStatus::Applied->canTransitionTo(ApplicationStatus::Rejected));

        // 応募中からキャンセルはできない（キャンセルは確定済みのみ。F-13）
        $this->assertFalse(ApplicationStatus::Applied->canTransitionTo(ApplicationStatus::Cancelled));
        $this->assertFalse(ApplicationStatus::Applied->canTransitionTo(ApplicationStatus::Applied));
    }

    public function test_確定からキャンセルへ遷移できる(): void
    {
        $this->assertTrue(ApplicationStatus::Confirmed->canTransitionTo(ApplicationStatus::Cancelled));

        $this->assertFalse(ApplicationStatus::Confirmed->canTransitionTo(ApplicationStatus::Rejected));
        $this->assertFalse(ApplicationStatus::Confirmed->canTransitionTo(ApplicationStatus::Confirmed));
    }

    public function test_却下とキャンセルからはどこへも遷移できない(): void
    {
        foreach ([ApplicationStatus::Rejected, ApplicationStatus::Cancelled] as $terminal) {
            foreach (ApplicationStatus::cases() as $next) {
                $this->assertFalse(
                    $terminal->canTransitionTo($next),
                    "{$terminal->value} → {$next->value} は遷移できてはいけない",
                );
            }
        }
    }

    public function test_確定から応募中へは戻れない(): void
    {
        $this->assertFalse(ApplicationStatus::Confirmed->canTransitionTo(ApplicationStatus::Applied));
    }

    public function test_募集はopenからclosedとcancelledへ、closedからcancelledへ遷移できる(): void
    {
        $this->assertTrue(JobPostingStatus::Open->canTransitionTo(JobPostingStatus::Closed));
        $this->assertTrue(JobPostingStatus::Open->canTransitionTo(JobPostingStatus::Cancelled));
        $this->assertTrue(JobPostingStatus::Closed->canTransitionTo(JobPostingStatus::Cancelled));

        // 締切から募集中には戻さない。中止は終端
        $this->assertFalse(JobPostingStatus::Closed->canTransitionTo(JobPostingStatus::Open));
        $this->assertFalse(JobPostingStatus::Open->canTransitionTo(JobPostingStatus::Open));
        foreach (JobPostingStatus::cases() as $next) {
            $this->assertFalse(JobPostingStatus::Cancelled->canTransitionTo($next));
        }
    }

    public function test_状態と理由区分のラベルは日本語(): void
    {
        $this->assertSame(
            ['applied' => '応募中', 'confirmed' => '確定', 'rejected' => '却下', 'cancelled' => 'キャンセル'],
            array_combine(
                array_column(ApplicationStatus::cases(), 'value'),
                array_map(fn (ApplicationStatus $s) => $s->label(), ApplicationStatus::cases()),
            ),
        );
        $this->assertSame(
            ['open' => '募集中', 'closed' => '締切', 'cancelled' => '中止'],
            array_combine(
                array_column(JobPostingStatus::cases(), 'value'),
                array_map(fn (JobPostingStatus $s) => $s->label(), JobPostingStatus::cases()),
            ),
        );
        $this->assertSame('企業都合', CancelReason::Company->label());
        $this->assertSame('ワーカー都合', CancelReason::Worker->label());
    }

    /**
     * 応募の遷移表（docs/01 4.1、myteam-domain-rules）を「許可される組」だけ書き、残りは全部禁止として 4 × 4 = 16 組を総当たりする。
     * 実装の canTransitionTo() とは独立に、仕様の表をここに直接書く（実装を写さない）
     *
     * @return iterable<string, array{ApplicationStatus, ApplicationStatus, bool}>
     */
    public static function applicationTransitionPairs(): iterable
    {
        $allowed = ['applied>confirmed', 'applied>rejected', 'confirmed>cancelled'];

        foreach (ApplicationStatus::cases() as $from) {
            foreach (ApplicationStatus::cases() as $to) {
                $key = "{$from->value}>{$to->value}";
                yield $key => [$from, $to, in_array($key, $allowed, true)];
            }
        }
    }

    #[DataProvider('applicationTransitionPairs')]
    public function test_応募の全16組の遷移可否が遷移表どおり(ApplicationStatus $from, ApplicationStatus $to, bool $expected): void
    {
        $this->assertSame($expected, $from->canTransitionTo($to));
    }

    /**
     * 募集の遷移表（docs/01 4.1）: open → closed / cancelled、closed → cancelled。3 × 3 = 9 組を総当たりする
     *
     * @return iterable<string, array{JobPostingStatus, JobPostingStatus, bool}>
     */
    public static function jobPostingTransitionPairs(): iterable
    {
        $allowed = ['open>closed', 'open>cancelled', 'closed>cancelled'];

        foreach (JobPostingStatus::cases() as $from) {
            foreach (JobPostingStatus::cases() as $to) {
                $key = "{$from->value}>{$to->value}";
                yield $key => [$from, $to, in_array($key, $allowed, true)];
            }
        }
    }

    #[DataProvider('jobPostingTransitionPairs')]
    public function test_募集の全9組の遷移可否が遷移表どおり(JobPostingStatus $from, JobPostingStatus $to, bool $expected): void
    {
        $this->assertSame($expected, $from->canTransitionTo($to));
    }

    public function test_enumの値の集合はdocs01の4_1と一致する(): void
    {
        $this->assertSame(['applied', 'confirmed', 'rejected', 'cancelled'], array_column(ApplicationStatus::cases(), 'value'));
        $this->assertSame(['open', 'closed', 'cancelled'], array_column(JobPostingStatus::cases(), 'value'));
        $this->assertSame(['company', 'worker'], array_column(CancelReason::cases(), 'value'));
    }
}
