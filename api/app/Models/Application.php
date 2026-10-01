<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Exceptions\DomainException;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * 応募（F-09〜F-14）。
 * status は #[Fillable] に入れず、transitionTo() だけが変える（呼ぶのは ApplicationService / JobPostingService）。
 * 確定時の時給・交通費（confirmed_*）や cancel_reason も、状態と一緒に transitionTo() の $attributes で入れる。
 *
 * @property ApplicationStatus $status
 * @property CancelReason|null $cancel_reason
 */
#[Fillable(['job_posting_id', 'worker_id'])]
class Application extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ApplicationFactory> */
    use HasFactory;

    /**
     * DB の既定値と揃える（docs/01 4.3）
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ApplicationStatus::Applied->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'cancel_reason' => CancelReason::class,
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'confirmed_hourly_wage' => 'integer',
            'confirmed_transport_allowance' => 'integer',
        ];
    }

    /**
     * 状態を変える唯一の入口。遷移表は ApplicationStatus::canTransitionTo()（docs/01 4.3）。
     *
     * 1. DB の最新の状態を読み直してから判定する（$this->refresh()）。別のリクエストが先に却下・承認・中止していても、
     *    古いインスタンスの status で判定しない。**サービスはこれを DB::transaction の中で呼ぶ**こと。
     *    SQLite の transaction_mode=IMMEDIATE でトランザクションの開始が直列化されるので、読み直し〜保存の間に割り込まれない
     * 2. 遷移できなければ DomainException（422。画面にそのまま出る）
     * 3. $attributes は「その状態に付随する列」をちょうど全部渡す。欠け・余分は呼び出し側のバグなので LogicException
     *    - Confirmed: confirmed_at / confirmed_hourly_wage / confirmed_transport_allowance（確定時に募集の値をコピーして固定）
     *    - Cancelled: cancel_reason / cancelled_at
     *    - Rejected: なし
     *
     * 例: transitionTo(ApplicationStatus::Cancelled, ['cancel_reason' => CancelReason::Company, 'cancelled_at' => now()])
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(ApplicationStatus $next, array $attributes = []): void
    {
        $this->refresh();

        if (! $this->status->canTransitionTo($next)) {
            throw new DomainException("{$this->status->label()}の応募は{$next->label()}にできません。");
        }

        self::assertTransitionAttributes($next, $attributes);

        // status は Fillable ではないので forceFill で入れる
        $this->forceFill([...$attributes, 'status' => $next])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function assertTransitionAttributes(ApplicationStatus $next, array $attributes): void
    {
        $required = match ($next) {
            ApplicationStatus::Confirmed => ['confirmed_at', 'confirmed_hourly_wage', 'confirmed_transport_allowance'],
            ApplicationStatus::Cancelled => ['cancel_reason', 'cancelled_at'],
            ApplicationStatus::Applied, ApplicationStatus::Rejected => [],
        };

        $missing = array_filter($required, fn (string $column) => ($attributes[$column] ?? null) === null);
        $unexpected = array_diff(array_keys($attributes), $required);

        if ($missing !== [] || $unexpected !== []) {
            throw new LogicException(sprintf(
                '%s への遷移の列が契約と違います（不足: %s / 許可されていない: %s）。',
                $next->value,
                implode(', ', $missing) ?: 'なし',
                implode(', ', $unexpected) ?: 'なし',
            ));
        }
    }

    /**
     * 同じ企業でなければならない親（BelongsToCompany が保存時に確かめる）
     *
     * @return array<string, class-string<Model>>
     */
    public function companyScopedParents(): array
    {
        return ['job_posting_id' => JobPosting::class, 'worker_id' => Worker::class];
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return HasOne<Attendance, $this>
     */
    public function attendance(): HasOne
    {
        return $this->hasOne(Attendance::class);
    }
}
