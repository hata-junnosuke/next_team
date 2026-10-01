<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Events\ApplicationConfirmed;
use App\Exceptions\DomainException;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 応募・確定の業務ルール（F-09〜F-14、docs/01 4.5・4.6）。
 *
 * 状態は Application::transitionTo() だけで変える。どれも DB::transaction の中で呼ぶ
 * （SQLite の transaction_mode=IMMEDIATE でトランザクションの開始が直列化されるので、承認と承認・承認と中止が同時に来ても
 * 後の方は先の方のコミットを待ってから読む。docs/01 12 章「定員チェックの一貫性」）。
 * lockForUpdate() は SQLite では何もしない（文法が空）が、意図を示すのと MySQL に移したときにそのまま効くように書く。
 * ロックの順は常に「募集 → 応募」にそろえる（JobPostingService::cancel も募集を先に変える）。順が逆の経路があると MySQL でデッドロックする。
 * https://readouble.com/laravel/13.x/ja/database.html#database-transactions
 * https://readouble.com/laravel/13.x/ja/queries.html#pessimistic-locking
 */
class ApplicationService
{
    /**
     * 応募の代行入力（F-09）。募集中の募集に、有効なワーカーを 1 回だけ応募させる。
     * ワーカーが自社のものかは StoreApplicationRequest（Rule::exists + company_id）とモデルの saving が見る。
     */
    public function apply(JobPosting $posting, Worker $worker): Application
    {
        try {
            return DB::transaction(function () use ($posting, $worker) {
                // ルートモデルバインディングで読んだ値は古いことがある（別のリクエストが締め切った・中止した直後など）ので、
                // 募集の行をロックして読み直す。締切・中止と同時に来ても、その結果を見てから判定する
                $posting = JobPosting::query()->lockForUpdate()->findOrFail($posting->id);
                $worker->refresh();

                if (! $posting->status->acceptsApplications()) {
                    throw new DomainException(match ($posting->status) {
                        JobPostingStatus::Closed => '締め切られた募集には応募できません。',
                        default => '中止された募集には応募できません。',
                    });
                }

                if (! $worker->is_active) {
                    throw new DomainException('無効のワーカーは応募できません。', field: 'worker_id');
                }

                if ($posting->applications()->where('worker_id', $worker->id)->exists()) {
                    throw self::duplicated();
                }

                return Application::create(['job_posting_id' => $posting->id, 'worker_id' => $worker->id]);
            });
        } catch (UniqueConstraintViolationException) {
            // 上の exists() と INSERT の間に同じ応募が入った場合（DB の UNIQUE (job_posting_id, worker_id) が最後の砦）。
            // DB::transaction はロールバックしてから例外を投げ直すので、トランザクションの外で 422 に変換する
            throw self::duplicated();
        }
    }

    /**
     * 承認（F-11）。応募中だけを確定にする。確定数が定員に達していたら拒否する（却下・キャンセルは数えない）。
     * 締切後も応募中の応募は承認できる（締切は新規応募の受付停止だけ）。中止された募集の応募は承認できない。
     * 確定時の時給・交通費を募集からコピーして固定する（以後の募集の編集は報酬に影響しない）。
     * コミット後に ApplicationConfirmed を発火する（ShouldDispatchAfterCommit）。
     */
    public function confirm(Application $application): Application
    {
        return DB::transaction(function () use ($application) {
            // 1. 募集の行をロックする。同じ募集への承認・中止はここで 1 つずつになる（MySQL では行ロック、SQLite では IMMEDIATE）。
            //    確定数の「数える → 確定にする」の間に別の承認が割り込むと定員を超えるので、数える前に親の行で直列化する
            $posting = JobPosting::query()->lockForUpdate()->findOrFail($application->job_posting_id);

            // 2. 応募もロックして取り直し、最新の状態で判定する（ロック待ちの間に却下・中止されていることがある）
            $locked = Application::query()->lockForUpdate()->findOrFail($application->id);
            $this->assertCanTransition($locked, ApplicationStatus::Confirmed);

            // 中止すると応募中は却下になるので普通は残らないが、残っていても承認しない（F-07、docs/01 4.6）
            if ($posting->status === JobPostingStatus::Cancelled) {
                throw new DomainException('中止された募集の応募は承認できません。');
            }

            // 3. 確定数を数える
            $confirmed = $posting->confirmedApplications()->lockForUpdate()->count();
            if ($confirmed >= $posting->capacity) {
                throw new DomainException('定員に達しているため承認できません。');
            }

            // 4. 遷移（transitionTo は refresh してから遷移表で判定する）
            $application->transitionTo(ApplicationStatus::Confirmed, [
                'confirmed_at' => CarbonImmutable::now(),
                'confirmed_hourly_wage' => $posting->hourly_wage,
                'confirmed_transport_allowance' => $posting->transport_allowance,
            ]);

            ApplicationConfirmed::dispatch($application->id);

            return $application;
        });
    }

    /**
     * 却下（F-12）。応募中だけ。定員には影響しない。
     */
    public function reject(Application $application): Application
    {
        return DB::transaction(function () use ($application) {
            $this->assertCanTransition(self::lockFresh($application), ApplicationStatus::Rejected);
            $application->transitionTo(ApplicationStatus::Rejected);

            return $application;
        });
    }

    /**
     * キャンセル（F-13）。確定済みだけ。理由区分（企業都合 / ワーカー都合）は必須。キャンセルで定員が 1 枠空く。
     */
    public function cancel(Application $application, CancelReason $reason): Application
    {
        return DB::transaction(function () use ($application, $reason) {
            $this->assertCanTransition(self::lockFresh($application), ApplicationStatus::Cancelled);
            $application->transitionTo(ApplicationStatus::Cancelled, [
                'cancel_reason' => $reason,
                'cancelled_at' => CarbonImmutable::now(),
            ]);

            return $application;
        });
    }

    /**
     * 応募できるワーカー（docs/01 6.4 の applicable-workers）。有効で、まだこの募集に応募していない自社のワーカー。
     * 却下・キャンセル済みの応募があるワーカーも、UNIQUE (job_posting_id, worker_id) で再応募できないので出さない。
     *
     * @return Collection<int, Worker>
     */
    public function applicableWorkers(JobPosting $posting): Collection
    {
        // whereDoesntHave のサブクエリにも Application のグローバルスコープ（自社）が効く
        return Worker::query()
            ->where('is_active', true)
            ->whereDoesntHave('applications', fn (Builder $q) => $q->where('job_posting_id', $posting->id))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);
    }

    /**
     * 応募をロックして DB から取り直す（ルートモデルバインディングで読んだインスタンスは古いことがある）
     */
    private static function lockFresh(Application $application): Application
    {
        return Application::query()->lockForUpdate()->findOrFail($application->id);
    }

    /**
     * 画面に出す文言で遷移できるかを先に確かめる。Application::transitionTo() も遷移表で拒否するが、
     * 承認では定員を数える前に状態で弾きたい（却下済みの応募に「定員に達しています」と出さない）のと、
     * 遷移表の文言（「却下の応募は確定にできません。」）より自然な文言にしたいので、ここで先に判定する。
     * 判定の正は遷移表（ApplicationStatus::canTransitionTo()）のまま。$application は取り直した最新のもの。
     */
    private function assertCanTransition(Application $application, ApplicationStatus $next): void
    {
        $current = $application->status;
        if ($current->canTransitionTo($next)) {
            return;
        }

        throw new DomainException(match ($next) {
            ApplicationStatus::Confirmed => match ($current) {
                ApplicationStatus::Confirmed => 'この応募はすでに確定しています。',
                ApplicationStatus::Rejected => '却下された応募は承認できません。',
                default => 'キャンセルされた応募は承認できません。',
            },
            ApplicationStatus::Rejected => match ($current) {
                ApplicationStatus::Confirmed => '確定した応募は却下できません。',
                ApplicationStatus::Rejected => 'この応募はすでに却下されています。',
                default => 'キャンセルされた応募は却下できません。',
            },
            ApplicationStatus::Cancelled => $current === ApplicationStatus::Cancelled
                ? 'この応募はすでにキャンセルされています。'
                : '確定済みの応募だけキャンセルできます。',
            ApplicationStatus::Applied => '応募中には戻せません。',
        });
    }

    private static function duplicated(): DomainException
    {
        return new DomainException('このワーカーはすでにこの募集に応募しています。', field: 'worker_id');
    }
}
