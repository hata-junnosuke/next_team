<?php

namespace Tests\Unit;

use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Enums\ReportExportStatus;
use App\Models\NotificationLog;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 通知の送信状態（notification_logs.status）の遷移表と、Enum の値・ラベル（docs/01 4.1）。
 * 遷移表は NotificationStatus::canTransitionTo() の 1 箇所。transitionTo() は DB を読み直すので Tests\TestCase を使う。
 */
class NotificationStatusTest extends TestCase
{
    /**
     * @return array<string, array{NotificationStatus, NotificationStatus, bool}>
     */
    public static function transitions(): array
    {
        $allowed = [
            'pending' => ['sent', 'failed'],
            'failed' => ['sent', 'failed'],
            'sent' => [],
        ];

        $cases = [];
        foreach (NotificationStatus::cases() as $from) {
            foreach (NotificationStatus::cases() as $to) {
                $cases["{$from->value} → {$to->value}"] = [$from, $to, in_array($to->value, $allowed[$from->value], true)];
            }
        }

        return $cases;
    }

    #[DataProvider('transitions')]
    public function test_通知の状態の遷移は全9組で遷移表どおり(NotificationStatus $from, NotificationStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public function test_送信待ちにはどの状態からも戻れない(): void
    {
        foreach (NotificationStatus::cases() as $from) {
            $this->assertFalse($from->canTransitionTo(NotificationStatus::Pending), "{$from->value} → pending");
        }
    }

    public function test_作った直後の記録は送信待ち(): void
    {
        $log = NotificationLog::factory()->create();

        $this->assertSame(NotificationStatus::Pending, $log->status);
        $this->assertSame('pending', $log->fresh()->getRawOriginal('status'));
    }

    public function test_送信済みの古いインスタンスからでも失敗に戻せない(): void
    {
        $log = NotificationLog::factory()->create();
        $stale = NotificationLog::query()->findOrFail($log->id);
        $log->transitionTo(NotificationStatus::Sent, ['sent_at' => now()]);

        try {
            $stale->transitionTo(NotificationStatus::Failed, ['failed_at' => now(), 'error' => '遅れて届いた失敗']);
            $this->fail('送信済みを失敗に戻せてしまった');
        } catch (LogicException) {
            $fresh = $log->fresh();
            $this->assertSame(NotificationStatus::Sent, $fresh->status);
            $this->assertNull($fresh->error);
            $this->assertNull($fresh->failed_at);
        }
    }

    public function test_送信済みにするときsent_atがないとlogic_exceptionで保存しない(): void
    {
        $log = NotificationLog::factory()->create();

        try {
            $log->transitionTo(NotificationStatus::Sent);
            $this->fail('sent_at なしで送信済みにできてしまった');
        } catch (LogicException) {
            $this->assertSame(NotificationStatus::Pending, $log->fresh()->status);
        }
    }

    public function test_送信済みにするとき失敗の列を渡すとlogic_exceptionで保存しない(): void
    {
        $log = NotificationLog::factory()->create();

        try {
            $log->transitionTo(NotificationStatus::Sent, ['sent_at' => now(), 'error' => '混ぜてはいけない列']);
            $this->fail('送信済みへの遷移で error を書けてしまった');
        } catch (LogicException) {
            $fresh = $log->fresh();
            $this->assertSame(NotificationStatus::Pending, $fresh->status);
            $this->assertNull($fresh->error);
        }
    }

    public function test_p4のenumの値とラベルはdocs01の4_1どおり(): void
    {
        $this->assertSame([
            'application_confirmed' => '確定通知',
            'posting_cancelled' => '中止通知',
            'shift_reminder' => '前日リマインド',
            'export_ready' => '出力完了',
        ], $this->labels(NotificationKind::cases()));
        $this->assertSame([
            'pending' => '送信待ち',
            'sent' => '送信済み',
            'failed' => '失敗',
        ], $this->labels(NotificationStatus::cases()));
        $this->assertSame([
            'queued' => '受付',
            'processing' => '生成中',
            'completed' => '完了',
            'failed' => '失敗',
        ], $this->labels(ReportExportStatus::cases()));
    }

    /**
     * @param  list<NotificationKind|NotificationStatus|ReportExportStatus>  $cases
     * @return array<string, string>
     */
    private function labels(array $cases): array
    {
        $labels = [];
        foreach ($cases as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }
}
