<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Events\JobPostingCancelled;
use App\Exceptions\DomainException;
use App\Models\JobPosting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 募集の業務ルール（F-05〜F-08、docs/01 のサービス契約・業務ルールの決定）。
 * 作成は単純な CRUD なのでコントローラから Eloquent を直接呼ぶ。ルールがある編集・締切・中止・複製をここに置く。
 *
 * 状態は JobPosting::transitionTo() / Application::transitionTo() だけで変える。どちらも DB::transaction の中で呼ぶ
 * （SQLite の transaction_mode=IMMEDIATE で、中止と承認が同時に来ても直列になる）。
 * https://readouble.com/laravel/13.x/ja/database.html#database-transactions
 */
class JobPostingService
{
    /**
     * 編集（F-05）。中止した募集は編集できない。締切は編集できる（受付を止めただけで、募集自体は生きている）。
     * 募集人数は確定数より少なくできない（応募中・却下・キャンセルは数えない）。
     *
     * @param  array<string, mixed>  $data  FormRequest の validated()
     */
    public function update(JobPosting $posting, array $data): JobPosting
    {
        return DB::transaction(function () use ($posting, $data) {
            // ルートモデルバインディングで読んだ値は古いことがある（別のリクエストが中止した直後など）ので読み直してから判定する
            $posting->refresh();

            if (! $posting->status->isEditable()) {
                throw new DomainException('中止した募集は編集できません。');
            }

            $confirmed = $posting->confirmedApplications()->count();
            if ((int) $data['capacity'] < $confirmed) {
                throw new DomainException("募集人数は確定数（{$confirmed}名）より少なくできません。", field: 'capacity');
            }

            $posting->update($data);

            return $posting;
        });
    }

    /**
     * 締切（F-07）。新規応募の受付を止めるだけで、応募中の応募は締切後も承認できる（承認は ApplicationService）。
     */
    public function close(JobPosting $posting): JobPosting
    {
        return DB::transaction(function () use ($posting) {
            $this->assertCanTransition($posting, JobPostingStatus::Closed);
            $posting->transitionTo(JobPostingStatus::Closed);

            return $posting;
        });
    }

    /**
     * 中止（F-07）。募集を中止にし、確定済みの応募は企業都合キャンセルに、応募中の応募は却下にする。
     * 却下・キャンセル済み（終端）の応募はそのまま。
     * コミット後に JobPostingCancelled（今回キャンセルになった応募の ID）を発火する（ShouldDispatchAfterCommit）。
     */
    public function cancel(JobPosting $posting): JobPosting
    {
        return DB::transaction(function () use ($posting) {
            $this->assertCanTransition($posting, JobPostingStatus::Cancelled);
            $posting->transitionTo(JobPostingStatus::Cancelled);

            $now = CarbonImmutable::now();
            $cancelledIds = [];

            $applications = $posting->applications()
                ->whereIn('status', [ApplicationStatus::Applied, ApplicationStatus::Confirmed])
                ->orderBy('id')
                ->get();

            foreach ($applications as $application) {
                if ($application->status === ApplicationStatus::Confirmed) {
                    $application->transitionTo(ApplicationStatus::Cancelled, [
                        'cancel_reason' => CancelReason::Company,
                        'cancelled_at' => $now,
                    ]);
                    $cancelledIds[] = $application->id;
                } else {
                    $application->transitionTo(ApplicationStatus::Rejected);
                }
            }

            JobPostingCancelled::dispatch($posting->id, $cancelledIds);

            return $posting;
        });
    }

    /**
     * 複製（F-08）。同じ条件で別の勤務日の募集を作る。状態は募集中（既定値）、応募は引き継がない。
     * 元の募集の状態は問わない（締切・中止した募集を別日に出し直す使い方を想定）。
     * 「元と同じ勤務日は不可」は入力の検証なので DuplicateJobPostingRequest が見る。
     * 時給は元の募集の値を引き継ぐので、作成後に最低賃金が上がっていたら今の下限で拒否する（作成・編集と同じ基準）。
     */
    public function duplicate(JobPosting $posting, CarbonImmutable $workDate): JobPosting
    {
        $minWage = (int) config('myteam.min_wage');
        if ($posting->hourly_wage < $minWage) {
            throw new DomainException("時給が最低賃金（{$minWage}円）未満のため複製できません。", field: 'hourly_wage');
        }

        return DB::transaction(fn () => JobPosting::create([
            ...$posting->only(['location_id', 'job_title', 'start_time', 'end_time', 'capacity', 'hourly_wage', 'transport_allowance']),
            'work_date' => $workDate->toDateString(),
        ]));
    }

    /**
     * 締切・中止の前に、画面に出す文言で遷移できるかを確かめる。
     * JobPosting::transitionTo() も遷移表で拒否するが、その文言（「締切の募集は締切にできません。」）は
     * 同じ状態への操作だと不自然なので、ここで先に分かりやすい文言の DomainException（422）にする。
     * 判定の正は遷移表（JobPostingStatus::canTransitionTo()）のまま。DB の最新の状態で判定するため読み直す。
     */
    private function assertCanTransition(JobPosting $posting, JobPostingStatus $next): void
    {
        $posting->refresh();

        if ($posting->status->canTransitionTo($next)) {
            return;
        }

        throw new DomainException(match (true) {
            $posting->status === JobPostingStatus::Cancelled && $next === JobPostingStatus::Cancelled => 'この募集はすでに中止されています。',
            $posting->status === JobPostingStatus::Cancelled => '中止した募集は締め切れません。',
            $posting->status === $next => "この募集はすでに{$next->label()}です。",
            default => "{$posting->status->label()}の募集は{$next->label()}にできません。",
        });
    }
}
