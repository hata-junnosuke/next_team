<?php

namespace Database\Factories;

use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Models\Application;
use App\Models\NotificationLog;
use App\Models\ReportExport;
use App\Services\NotificationLogService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * 既定は「確定済みの応募に対する、そのワーカーへの確定通知（pending）」。出力完了は forExport()。
 * company_id は BelongsToCompany の creating で CurrentCompany::id() が入る。
 * 本番の行は NotificationLogService::claim() だけが作る。ここはテストの前提を作るためのもの。
 * *_type は getMorphClass()（morph map の短い名前）、dedupe_key は NotificationLogService::dedupeKey() で作り、本番と同じ形にする。
 *
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => NotificationKind::ApplicationConfirmed,
            'subject_type' => (new Application)->getMorphClass(),
            'subject_id' => Application::factory()->confirmed(),
            'recipient_type' => (new Application)->worker()->getRelated()->getMorphClass(),
            'recipient_id' => fn (array $attributes) => self::subject($attributes)->getAttribute('worker_id'),
            'recipient_email' => fn (array $attributes) => self::recipient($attributes)->getAttribute('email'),
            'dedupe_key' => fn (array $attributes) => NotificationLogService::dedupeKey(
                $attributes['kind'] instanceof NotificationKind ? $attributes['kind'] : NotificationKind::from($attributes['kind']),
                self::subject($attributes),
            ),
            'status' => NotificationStatus::Pending,
        ];
    }

    /**
     * 出力完了（F-23）: 対象は集計出力、宛先は依頼した管理者
     */
    public function forExport(): static
    {
        return $this->state([
            'kind' => NotificationKind::ExportReady,
            'subject_type' => (new ReportExport)->getMorphClass(),
            'subject_id' => ReportExport::factory(),
            'recipient_type' => (new ReportExport)->user()->getRelated()->getMorphClass(),
            'recipient_id' => fn (array $attributes) => self::subject($attributes)->getAttribute('user_id'),
        ]);
    }

    /**
     * 送信済み
     */
    public function sent(): static
    {
        return $this->state([
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    /**
     * 失敗（全部の試行が失敗した後）。error は NotificationLogService が残す形（クラス名と固定の文言）
     */
    public function failed(): static
    {
        return $this->state([
            'status' => NotificationStatus::Failed,
            'failed_at' => now(),
            'error' => 'TransportException: メールの送信に失敗しました。',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function subject(array $attributes): Model
    {
        return self::find($attributes['subject_type'], $attributes['subject_id']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function recipient(array $attributes): Model
    {
        return self::find($attributes['recipient_type'], $attributes['recipient_id']);
    }

    private static function find(string $morphType, mixed $id): Model
    {
        /** @var class-string<Model> $class */
        $class = Relation::getMorphedModel($morphType);

        return $class::query()->findOrFail($id);
    }
}
