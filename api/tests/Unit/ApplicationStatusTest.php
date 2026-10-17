<?php

namespace Tests\Unit;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Exceptions\DomainException;
use App\Models\Application;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 状態遷移（F-14、要件の非機能要件で求める必須テスト）。遷移表は Enum の canTransitionTo() の 1 箇所にある（docs/01 の Enum）。
 * Enum の遷移表と、Application::transitionTo()（状態を変える唯一の入口）の例外の両方を確かめる。
 *
 * transitionTo() は DB を読み直してから判定する（refresh）ので、アプリと DB を起動する Tests\TestCase を使う
 * （Enum だけのテストもそのまま動く。Rails のモデルのユニットテストに相当）。
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
     * 応募の遷移表（docs/01 の Enum、myteam-domain-rules）を「許可される組」だけ書き、残りは全部禁止として 4 × 4 = 16 組を総当たりする。
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
     * 募集の遷移表（docs/01 の Enum）: open → closed / cancelled、closed → cancelled。3 × 3 = 9 組を総当たりする
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

    public function test_transition_toは不正な遷移で_domain_exceptionを投げる(): void
    {
        $rejected = Application::factory()->rejected()->create();

        try {
            $rejected->transitionTo(ApplicationStatus::Confirmed, [
                'confirmed_at' => now(),
                'confirmed_hourly_wage' => 1300,
                'confirmed_transport_allowance' => 0,
            ]);
            $this->fail('却下 → 確定 は DomainException になるはず');
        } catch (DomainException $e) {
            $this->assertSame('却下の応募は確定にできません。', $e->getMessage());
        }

        $this->assertSame(ApplicationStatus::Rejected, $rejected->fresh()->status);
        $this->assertNull($rejected->fresh()->confirmed_at);
    }

    public function test_transition_toは古いインスタンスでもdbの最新の状態で判定する(): void
    {
        $stale = Application::factory()->create();
        // 別のリクエストが先に却下した（$stale のメモリ上の status は応募中のまま）
        Application::query()->findOrFail($stale->id)->transitionTo(ApplicationStatus::Rejected);

        $this->expectException(DomainException::class);
        $stale->transitionTo(ApplicationStatus::Rejected);
    }

    public function test_transition_toは付随列が欠けると_logic_exceptionで状態を変えない(): void
    {
        $confirmed = Application::factory()->confirmed()->create();

        try {
            // キャンセルには cancel_reason と cancelled_at が必要（cancelled_at が欠けている）
            $confirmed->transitionTo(ApplicationStatus::Cancelled, ['cancel_reason' => CancelReason::Worker]);
            $this->fail('付随列が欠けたら LogicException になるはず');
        } catch (LogicException $e) {
            $this->assertNotInstanceOf(DomainException::class, $e);
        }

        $this->assertSame(ApplicationStatus::Confirmed, $confirmed->fresh()->status);
    }

    // ---- テスト補強（tester） ----

    /**
     * 遷移先ごとの「契約どおりの付随列」。禁止の遷移が付随列の不足ではなく遷移表で拒否されることを見るために、列は揃えて渡す
     *
     * @return array<string, mixed>
     */
    private static function attributesFor(ApplicationStatus $next): array
    {
        return match ($next) {
            ApplicationStatus::Confirmed => ['confirmed_at' => now(), 'confirmed_hourly_wage' => 1300, 'confirmed_transport_allowance' => 500],
            ApplicationStatus::Cancelled => ['cancel_reason' => CancelReason::Company, 'cancelled_at' => now()],
            ApplicationStatus::Applied, ApplicationStatus::Rejected => [],
        };
    }

    private static function makeApplicationIn(ApplicationStatus $status): Application
    {
        $factory = Application::factory();

        return match ($status) {
            ApplicationStatus::Applied => $factory->create(),
            ApplicationStatus::Confirmed => $factory->confirmed()->create(),
            ApplicationStatus::Rejected => $factory->rejected()->create(),
            ApplicationStatus::Cancelled => $factory->cancelled()->create(),
        };
    }

    /**
     * 遷移表で禁止の 13 組（16 組 − 許可の 3 組）
     *
     * @return iterable<string, array{ApplicationStatus, ApplicationStatus}>
     */
    public static function forbiddenApplicationTransitions(): iterable
    {
        foreach (self::applicationTransitionPairs() as $key => [$from, $to, $allowed]) {
            if (! $allowed) {
                yield $key => [$from, $to];
            }
        }
    }

    #[DataProvider('forbiddenApplicationTransitions')]
    public function test_transition_toは禁止の13組すべてで_domain_exceptionを投げdbを変えない(ApplicationStatus $from, ApplicationStatus $to): void
    {
        $application = self::makeApplicationIn($from);
        $before = $application->fresh()->getAttributes();

        try {
            $application->transitionTo($to, self::attributesFor($to));
            $this->fail("{$from->value} → {$to->value} は DomainException になるはず");
        } catch (DomainException $e) {
            $this->assertSame("{$from->label()}の応募は{$to->label()}にできません。", $e->getMessage());
        }

        $this->assertSame($before, $application->fresh()->getAttributes());
    }

    /**
     * @return iterable<string, array{ApplicationStatus, ApplicationStatus}>
     */
    public static function allowedApplicationTransitions(): iterable
    {
        foreach (self::applicationTransitionPairs() as $key => [$from, $to, $allowed]) {
            if ($allowed) {
                yield $key => [$from, $to];
            }
        }
    }

    #[DataProvider('allowedApplicationTransitions')]
    public function test_transition_toは許可の3組で状態と付随列を保存する(ApplicationStatus $from, ApplicationStatus $to): void
    {
        $application = self::makeApplicationIn($from);

        $application->transitionTo($to, self::attributesFor($to));

        $fresh = $application->fresh();
        $this->assertSame($to, $fresh->status);
        foreach (array_keys(self::attributesFor($to)) as $column) {
            $this->assertNotNull($fresh->{$column}, "{$column} が保存されていない");
        }
    }

    public function test_transition_toは契約にない付随列を渡すと_logic_exceptionで状態を変えない(): void
    {
        $applied = Application::factory()->create();

        try {
            // 却下に確定の列を混ぜる（status 以外の列をついでに書き換える経路を作らない）
            $applied->transitionTo(ApplicationStatus::Rejected, ['confirmed_hourly_wage' => 1300]);
            $this->fail('契約にない列は LogicException になるはず');
        } catch (LogicException $e) {
            $this->assertNotInstanceOf(DomainException::class, $e);
        }

        $this->assertSame(ApplicationStatus::Applied, $applied->fresh()->status);
        $this->assertNull($applied->fresh()->confirmed_hourly_wage);
    }
}
