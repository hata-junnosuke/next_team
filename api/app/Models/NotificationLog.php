<?php

namespace App\Models;

use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 通知の記録（F-20〜F-24）。作る・状態を変えるのは NotificationLogService だけ（ジョブから直接触らない）。
 * status は #[Fillable] に入れず、transitionTo() だけが変える。
 * recipient（宛先: Worker / User）と subject（対象: Application / JobPosting / ReportExport）はポリモーフィック
 * （Rails の belongs_to :recipient, polymorphic: true）。*_type には morph map の短い名前が入る。
 * https://readouble.com/laravel/13.x/ja/eloquent-relationships.html#polymorphic-relationships
 *
 * @property NotificationKind $kind
 * @property NotificationStatus $status
 */
#[Fillable(['kind', 'recipient_type', 'recipient_id', 'subject_type', 'subject_id', 'recipient_email', 'dedupe_key'])]
class NotificationLog extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<NotificationLogFactory> */
    use HasFactory;

    /**
     * DB の既定値と揃える（docs/01 のモデル）
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => NotificationStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => NotificationKind::class,
            'status' => NotificationStatus::class,
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /**
     * status を変える唯一の入口（呼ぶのは NotificationLogService）。Application::transitionTo() と同じ型。
     *
     * 1. トランザクションの中で DB から読み直してから判定する。SQLite の transaction_mode=IMMEDIATE で開始が直列化されるので、
     *    2 つのワーカーが同時に呼んでも「読み直し → 判定 → 保存」の間に割り込まれない
     * 2. 遷移できないのは呼び出し側（ジョブ・サービス）のバグなので LogicException（画面に出す業務エラーではない）
     * 3. $attributes は「その状態に付随する列」をちょうど全部渡す。欠け・余分は LogicException
     *    - Sent: sent_at
     *    - Failed: failed_at / error
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(NotificationStatus $next, array $attributes = []): void
    {
        DB::transaction(function () use ($next, $attributes) {
            $this->refresh();

            if (! $this->status->canTransitionTo($next)) {
                throw new LogicException("通知の記録 {$this->id} は{$this->status->label()}なので{$next->label()}にできません。");
            }

            self::assertTransitionAttributes($next, $attributes);

            // status は Fillable ではないので forceFill で入れる
            $this->forceFill([...$attributes, 'status' => $next])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function assertTransitionAttributes(NotificationStatus $next, array $attributes): void
    {
        $required = match ($next) {
            NotificationStatus::Sent => ['sent_at'],
            NotificationStatus::Failed => ['failed_at', 'error'],
            NotificationStatus::Pending => [],
        };

        $missing = array_filter($required, fn (string $column) => ($attributes[$column] ?? null) === null);
        $unexpected = array_diff(array_keys($attributes), $required);

        if ($missing !== [] || $unexpected !== []) {
            throw new LogicException(sprintf(
                '通知の記録の %s への遷移の列が契約と違います（不足: %s / 許可されていない: %s）。',
                $next->value,
                implode(', ', $missing) ?: 'なし',
                implode(', ', $unexpected) ?: 'なし',
            ));
        }
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
