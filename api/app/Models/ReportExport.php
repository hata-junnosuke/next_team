<?php

namespace App\Models;

use App\Enums\ReportExportStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ReportExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 集計出力の依頼（F-23）。作る・状態を変えるのは ReportExportService とそのジョブ（P4-05）。
 * status は #[Fillable] に入れず、transitionTo() だけが変える。
 * batch_id / file_path / row_count / completed_at / failed_at も #[Fillable] に入れない
 * （file_path をリクエストの値で書き換えさせない。ダウンロードはこの列だけを使う）。
 *
 * @property ReportExportStatus $status
 */
#[Fillable(['user_id', 'month'])]
class ReportExport extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ReportExportFactory> */
    use HasFactory;

    /**
     * DB の既定値と揃える（docs/01 4.3）
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ReportExportStatus::Queued->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReportExportStatus::class,
            'row_count' => 'integer',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /**
     * 同じ企業でなければならない親（BelongsToCompany が保存時に確かめる）
     *
     * @return array<string, class-string<Model>>
     */
    public function companyScopedParents(): array
    {
        return ['user_id' => User::class];
    }

    /**
     * status を変える唯一の入口。Application::transitionTo() と同じ型。
     *
     * 1. トランザクションの中で DB から読み直してから判定する（IMMEDIATE で直列化。バッチの then と catch が前後しても、
     *    終端の状態を上書きしない）
     * 2. 遷移できないのは呼び出し側（ジョブ・サービス）のバグなので LogicException
     * 3. $attributes は「その状態に付随する列」をちょうど全部渡す。欠け・余分は LogicException
     *    - Processing: batch_id
     *    - Completed: file_path / row_count / completed_at（row_count は 0 でもよい）
     *    - Failed: failed_at
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(ReportExportStatus $next, array $attributes = []): void
    {
        DB::transaction(function () use ($next, $attributes) {
            $this->refresh();

            if (! $this->status->canTransitionTo($next)) {
                throw new LogicException("集計出力 {$this->id} は{$this->status->label()}なので{$next->label()}にできません。");
            }

            self::assertTransitionAttributes($next, $attributes);

            // status は Fillable ではないので forceFill で入れる
            $this->forceFill([...$attributes, 'status' => $next])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function assertTransitionAttributes(ReportExportStatus $next, array $attributes): void
    {
        $required = match ($next) {
            ReportExportStatus::Processing => ['batch_id'],
            ReportExportStatus::Completed => ['file_path', 'row_count', 'completed_at'],
            ReportExportStatus::Failed => ['failed_at'],
            ReportExportStatus::Queued => [],
        };

        $missing = array_filter($required, fn (string $column) => ($attributes[$column] ?? null) === null);
        $unexpected = array_diff(array_keys($attributes), $required);

        if ($missing !== [] || $unexpected !== []) {
            throw new LogicException(sprintf(
                '集計出力の %s への遷移の列が契約と違います（不足: %s / 許可されていない: %s）。',
                $next->value,
                implode(', ', $missing) ?: 'なし',
                implode(', ', $unexpected) ?: 'なし',
            ));
        }
    }

    /**
     * 依頼した管理者
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
