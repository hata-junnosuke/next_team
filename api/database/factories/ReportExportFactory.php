<?php

namespace Database\Factories;

use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は「今の企業の管理者が依頼した、先月分の受付（queued）」。
 * company_id は BelongsToCompany の creating で CurrentCompany::id() が入る。依頼者も同じ企業の管理者にする
 * （他社の管理者だと BelongsToCompany の companyScopedParents が LogicException で止める）。
 *
 * @extends Factory<ReportExport>
 */
class ReportExportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn () => User::factory()->state(['company_id' => CurrentCompany::id()]),
            'month' => now()->subMonthNoOverflow()->format('Y-m'),
            'status' => ReportExportStatus::Queued,
        ];
    }

    /**
     * 生成中（バッチを積んだ後）
     */
    public function processing(): static
    {
        return $this->state([
            'status' => ReportExportStatus::Processing,
            'batch_id' => fake()->uuid(),
        ]);
    }

    /**
     * 完了。file_path は exports/{company_id}/{export_id}/monthly-report-{month}.csv の形（ファイル自体は置かない）
     */
    public function completed(): static
    {
        return $this->state([
            'status' => ReportExportStatus::Completed,
            'batch_id' => fake()->uuid(),
            'row_count' => 3,
            'completed_at' => now(),
        ])->afterCreating(function (ReportExport $export) {
            $export->forceFill(['file_path' => "exports/{$export->company_id}/{$export->id}/monthly-report-{$export->month}.csv"])->save();
        });
    }

    /**
     * 失敗（バッチの catch）
     */
    public function failed(): static
    {
        return $this->state([
            'status' => ReportExportStatus::Failed,
            'batch_id' => fake()->uuid(),
            'failed_at' => now(),
        ]);
    }
}
