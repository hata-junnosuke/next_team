<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Events\ApplicationConfirmed;
use App\Exceptions\DomainException;
use App\Http\Middleware\AuthenticateWorker;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 応募・確定の業務ルール（F-09〜F-14、docs/01 のサービス契約・業務ルールの決定）。
 *
 * 状態は Application::transitionTo() だけで変える。どれも DB::transaction の中で呼ぶ
 * （SQLite の transaction_mode=IMMEDIATE でトランザクションの開始が直列化されるので、承認と承認・承認と中止が同時に来ても
 * 後の方は先の方のコミットを待ってから読む。docs/01 の設計判断「定員チェックの一貫性」）。
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
     * ロック・読み直し・UNIQUE 違反の変換は createApplication() が行う（applyAsWorker() と共有）。
     */
    public function apply(JobPosting $posting, Worker $worker): Application
    {
        return $this->createApplication($posting, $worker, function (JobPosting $posting, Worker $worker) {
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
        }, self::duplicated(...));
    }

    /**
     * ワーカー本人の応募（F-32。docs/01 の 4.5・6.11）。管理者の代行入力（apply()）と違い、残り枠 0 なら拒否する（要件 4.8）。
     * 文言はワーカー向けで、errors は空（ワーカーの画面には worker_id の入力欄がない）。行を作る処理とロックは apply() と共有する。
     * 判定の順（docs/01 の 4.5）: 無効 → 締切・中止 → 勤務日 → 二重 → 残り枠
     * （自分が確定して満員になった募集に「残り枠がない」と出さないよう、二重を残り枠より先に見る）。
     */
    public function applyAsWorker(JobPosting $posting, Worker $worker): Application
    {
        return $this->createApplication($posting, $worker, function (JobPosting $posting, Worker $worker) {
            // 無効のワーカーは普段は worker ミドルウェアが 401 にする。ミドルウェアを通った後・トランザクションの前に
            // 無効にされた場合の隙間を、トランザクションの中で読み直した is_active で塞ぐ（文言はミドルウェアと同じ。errors は空）
            if (! $worker->is_active) {
                throw new DomainException(AuthenticateWorker::DISABLED_MESSAGE);
            }

            if (! $posting->status->acceptsApplications()) {
                throw new DomainException(match ($posting->status) {
                    JobPostingStatus::Closed => '募集が締め切られているため応募できません。',
                    default => '募集が中止されたため応募できません。',
                });
            }

            // 日付だけで比べる（今日の勤務は開始時刻を過ぎても応募できる。F-30 の「今日以降」と同じ判定）
            if (! $posting->isOnOrAfterToday()) {
                throw new DomainException('勤務日が過ぎた募集には応募できません。');
            }

            if ($posting->applications()->where('worker_id', $worker->id)->exists()) {
                throw self::duplicatedForWorker();
            }

            // 確定数はロックした募集の下で数え直す（承認と同時に来ても、承認のコミット後の数で判定する）。式は JobPosting::remainingFrom() の 1 か所。
            // 応募の行には lockForUpdate() を付けない。確定数を増やす confirm() も先に募集の行をロックするので、親の行ロックで 1 本ずつになる。
            // 付けると MySQL の InnoDB では範囲の読み取りがギャップロックを取り、隣り合う募集への同時の応募どうしでデッドロックしうる
            $confirmed = $posting->confirmedApplications()->count();
            if (JobPosting::remainingFrom($posting->capacity, $confirmed) === 0) {
                throw new DomainException('残り枠がないため応募できません。');
            }
        }, self::duplicatedForWorker(...));
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

            // 中止すると応募中は却下になるので普通は残らないが、残っていても承認しない（F-07、docs/01 の業務ルールの決定）
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
     * 応募できるワーカー（docs/01 の API 契約（募集）の applicable-workers）。有効で、まだこの募集に応募していない自社のワーカー。
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
     * 応募の行を作る共通処理（apply() と applyAsWorker()）。募集の行をロックして読み直し、ワーカーも読み直してから
     * $guard（判定。拒否なら DomainException を投げる）を呼び、応募中の行を作る。
     * ルートモデルバインディングで読んだ値は古いことがある（別のリクエストが締め切った・中止した直後など）ので、
     * ロックした最新の募集で判定する。ロックの順は「募集 → 応募」（クラスの説明）。
     *
     * @param  Closure(JobPosting, Worker): void  $guard
     * @param  Closure(): DomainException  $duplicated  UNIQUE 違反を変換する例外（呼び出し側の文言）
     */
    private function createApplication(JobPosting $posting, Worker $worker, Closure $guard, Closure $duplicated): Application
    {
        try {
            return DB::transaction(function () use ($posting, $worker, $guard) {
                $posting = JobPosting::query()->lockForUpdate()->findOrFail($posting->id);
                $worker->refresh();

                $guard($posting, $worker);

                return Application::create(['job_posting_id' => $posting->id, 'worker_id' => $worker->id]);
            });
        } catch (UniqueConstraintViolationException) {
            // $guard の exists() と INSERT の間に同じ応募が入った場合（DB の UNIQUE (job_posting_id, worker_id) が最後の砦）。
            // DB::transaction はロールバックしてから例外を投げ直すので、トランザクションの外で 422 に変換する
            throw $duplicated();
        }
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

    private static function duplicatedForWorker(): DomainException
    {
        return new DomainException('この募集にはすでに応募しています。');
    }
}
