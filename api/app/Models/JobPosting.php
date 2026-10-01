<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\JobPostingStatus;
use App\Exceptions\DomainException;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * 募集（F-05〜F-08）。
 * status は #[Fillable] に入れず、transitionTo() だけが変える（呼ぶのは JobPostingService）。
 * start_time / end_time は "09:00" の文字列で持つ（time 列。日付を持たないので cast しない）。
 *
 * @property JobPostingStatus $status
 */
#[Fillable(['location_id', 'job_title', 'work_date', 'start_time', 'end_time', 'capacity', 'hourly_wage', 'transport_allowance'])]
class JobPosting extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<JobPostingFactory> */
    use HasFactory;

    /**
     * DB の既定値と揃える（docs/01 4.3）
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => JobPostingStatus::Open->value,
        'transport_allowance' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
            'capacity' => 'integer',
            'hourly_wage' => 'integer',
            'transport_allowance' => 'integer',
            'status' => JobPostingStatus::class,
        ];
    }

    /**
     * 状態を変える唯一の入口。遷移表は JobPostingStatus::canTransitionTo()（docs/01 4.3）。
     *
     * DB の最新の状態を読み直してから判定する（$this->refresh()）。**サービスはこれを DB::transaction の中で呼ぶ**こと
     * （中止と承認・締切が同時に来ても、古いインスタンスの status で判定しない。直列化は SQLite の IMMEDIATE）。
     * 遷移できなければ DomainException（422）。
     * 募集には状態に付随する列がないので、$attributes に何か渡したら呼び出し側のバグとして LogicException
     * （引数は Application::transitionTo() と形を揃えるために残している）。
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(JobPostingStatus $next, array $attributes = []): void
    {
        $this->refresh();

        if (! $this->status->canTransitionTo($next)) {
            throw new DomainException("{$this->status->label()}の募集は{$next->label()}にできません。");
        }

        if ($attributes !== []) {
            throw new LogicException(sprintf(
                '募集の %s への遷移で一緒に保存できる列はありません（渡された列: %s）。',
                $next->value,
                implode(', ', array_keys($attributes)),
            ));
        }

        // status は Fillable ではないので forceFill で入れる
        $this->forceFill(['status' => $next])->save();
    }

    /**
     * 勤務日は "2026-10-10" の文字列で保存する。date の cast だけだと "2026-10-10 00:00:00" で保存され、
     * where('work_date', '2026-10-10') や半開区間の文字列比較が当たらなくなる（SQLite は日付を文字列で比較する）。
     * 読むときは casts() の immutable_date で CarbonImmutable になる。
     *
     * @return Attribute<CarbonImmutable, string>
     */
    protected function workDate(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value) => $value === null ? null : CarbonImmutable::parse($value)->toDateString(),
        );
    }

    /**
     * 同じ企業でなければならない親（BelongsToCompany が保存時に確かめる）
     *
     * @return array<string, class-string<Model>>
     */
    public function companyScopedParents(): array
    {
        return ['location_id' => Location::class];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * 確定済みの応募。withCount('confirmedApplications') で confirmed_count に使う。
     *
     * @return HasMany<Application, $this>
     */
    public function confirmedApplications(): HasMany
    {
        return $this->applications()->where('status', ApplicationStatus::Confirmed);
    }
}
