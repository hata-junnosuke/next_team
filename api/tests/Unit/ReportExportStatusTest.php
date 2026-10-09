<?php

namespace Tests\Unit;

use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use LogicException;
use Tests\TestCase;

/**
 * 集計出力の状態（F-23）の遷移表と ReportExport::transitionTo()。
 * 遷移表は ReportExportStatus::canTransitionTo() の 1 箇所にある。transitionTo() は DB を読み直すので Tests\TestCase を使う。
 */
class ReportExportStatusTest extends TestCase
{
    public function test_受付からは生成中と完了と失敗に進める(): void
    {
        $this->assertTrue(ReportExportStatus::Queued->canTransitionTo(ReportExportStatus::Processing));
        $this->assertTrue(ReportExportStatus::Queued->canTransitionTo(ReportExportStatus::Completed));
        $this->assertTrue(ReportExportStatus::Queued->canTransitionTo(ReportExportStatus::Failed));
        $this->assertFalse(ReportExportStatus::Processing->canTransitionTo(ReportExportStatus::Queued));
    }

    public function test_生成中からは完了と失敗にだけ進め同じ状態には留まれない(): void
    {
        $this->assertTrue(ReportExportStatus::Processing->canTransitionTo(ReportExportStatus::Completed));
        $this->assertTrue(ReportExportStatus::Processing->canTransitionTo(ReportExportStatus::Failed));
        $this->assertFalse(ReportExportStatus::Processing->canTransitionTo(ReportExportStatus::Processing));
        $this->assertFalse(ReportExportStatus::Queued->canTransitionTo(ReportExportStatus::Queued));
    }

    public function test_遷移できないときは一緒に渡した列も保存しない(): void
    {
        $export = ReportExport::factory()->completed()->create();
        $path = $export->fresh()->file_path;

        try {
            $export->transitionTo(ReportExportStatus::Failed, ['file_path' => 'exports/evil.csv']);
            $this->fail('完了から失敗に遷移できてしまった');
        } catch (LogicException) {
            $fresh = $export->fresh();
            $this->assertSame(ReportExportStatus::Completed, $fresh->status);
            $this->assertSame($path, $fresh->file_path);
        }
    }

    public function test_完了と失敗は終端(): void
    {
        foreach ([ReportExportStatus::Completed, ReportExportStatus::Failed] as $from) {
            foreach (ReportExportStatus::cases() as $to) {
                $this->assertFalse($from->canTransitionTo($to), "{$from->value} → {$to->value}");
            }
        }
    }

    public function test_生成中とみなすのは受付と生成中だけ(): void
    {
        $this->assertTrue(ReportExportStatus::Queued->isInProgress());
        $this->assertTrue(ReportExportStatus::Processing->isInProgress());
        $this->assertFalse(ReportExportStatus::Completed->isInProgress());
        $this->assertFalse(ReportExportStatus::Failed->isInProgress());
    }

    public function test_作った直後の状態は受付(): void
    {
        $export = ReportExport::create(['user_id' => $this->admin->id, 'month' => '2026-09']);

        $this->assertSame(ReportExportStatus::Queued, $export->status);
        $this->assertSame(ReportExportStatus::Queued->value, $export->fresh()->getRawOriginal('status'));
        $this->assertSame($this->company->id, $export->company_id);
    }

    public function test_遷移すると一緒に渡した列も保存される(): void
    {
        $export = ReportExport::factory()->processing()->create();

        $export->transitionTo(ReportExportStatus::Completed, ['file_path' => 'exports/1/1/monthly-report-2026-09.csv', 'row_count' => 5, 'completed_at' => now()]);

        $export->refresh();
        $this->assertSame(ReportExportStatus::Completed, $export->status);
        $this->assertSame('exports/1/1/monthly-report-2026-09.csv', $export->file_path);
        $this->assertSame(5, $export->row_count);
    }

    public function test_古いインスタンスでも最新の状態で判定し完了を上書きしない(): void
    {
        $export = ReportExport::factory()->processing()->create();
        $stale = ReportExport::query()->findOrFail($export->id);
        $export->transitionTo(ReportExportStatus::Completed, ['file_path' => 'exports/1/1/monthly-report-2026-09.csv', 'row_count' => 0, 'completed_at' => now()]);

        $this->expectException(LogicException::class);

        $stale->transitionTo(ReportExportStatus::Failed, ['failed_at' => now()]);
    }

    public function test_遷移先に付随する列が欠けているとlogic_exceptionで保存しない(): void
    {
        $export = ReportExport::factory()->processing()->create();

        try {
            $export->transitionTo(ReportExportStatus::Completed, ['file_path' => 'exports/1/1/monthly-report-2026-09.csv', 'row_count' => 5]);
            $this->fail('completed_at なしで完了にできてしまった');
        } catch (LogicException) {
            $this->assertSame(ReportExportStatus::Processing, $export->fresh()->status);
        }
    }

    public function test_遷移先に付随しない列を渡すとlogic_exceptionで保存しない(): void
    {
        $export = ReportExport::factory()->create();

        try {
            $export->transitionTo(ReportExportStatus::Processing, ['batch_id' => 'batch-1', 'file_path' => 'exports/evil.csv']);
            $this->fail('生成中への遷移で file_path を書けてしまった');
        } catch (LogicException) {
            $fresh = $export->fresh();
            $this->assertSame(ReportExportStatus::Queued, $fresh->status);
            $this->assertNull($fresh->file_path);
        }
    }

    public function test_拠点が0件なら受付から直接完了にでき行数は0でよい(): void
    {
        $export = ReportExport::factory()->create();

        $export->transitionTo(ReportExportStatus::Completed, ['file_path' => 'exports/1/1/monthly-report-2026-09.csv', 'row_count' => 0, 'completed_at' => now()]);

        $this->assertSame(ReportExportStatus::Completed, $export->fresh()->status);
        $this->assertSame(0, $export->fresh()->row_count);
    }

    public function test_完了のファクトリはfile_pathを決まった形で持つ(): void
    {
        $export = ReportExport::factory()->completed()->create(['month' => '2026-09']);

        $this->assertSame("exports/{$this->company->id}/{$export->id}/monthly-report-2026-09.csv", $export->fresh()->file_path);
    }
}
