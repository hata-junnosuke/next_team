<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\Worker;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * ワーカーアプリが見る募集と自分の応募（F-30・F-31・F-33。docs/01 の 4.5・4.6・6.11）。
 *
 * 企業の絞り込みは JobPosting のグローバルスコープ（worker ミドルウェアが CurrentCompany にワーカーの企業を入れる）に任せる。
 * 「募集中かつ勤務日が今日以降」・残り枠・応募できるかの判定は JobPosting の 1 か所（scopeListedForWorkers() /
 * isListedForWorkers() / remainingSlots() / canBeAppliedBy()）にあり、ここはそれを呼ぶだけ。
 *
 * 返す募集には WorkerJobPostingResource が使うものを読み込んでおく（Resource でクエリを発行しない = N+1 にしない）:
 * - location
 * - confirmed_applications_count（残り枠の元）
 * - myApplication（このワーカー自身の応募だけ。ほかのワーカーの応募は読み込まない = 応答に出しようがない）
 */
class WorkerPortalService
{
    /**
     * F-30 の一覧。自社の募集中・勤務日が今日以降。勤務日 → 開始時刻の昇順（同じなら ID 順で並びを固定する）。
     * $filters は IndexJobPostingRequest の validated()（date_from / date_to / location_id。どれも任意）
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, JobPosting>
     */
    public function searchPostings(Worker $worker, array $filters, int $perPage): LengthAwarePaginator
    {
        // work_date は "Y-m-d" の文字列で保存している（JobPosting::workDate()）ので、文字列の比較で日付の範囲になる
        $postings = JobPosting::query()
            ->listedForWorkers()
            ->with($this->relations($worker))
            ->withCount('confirmedApplications')
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $from) => $q->where('work_date', '>=', $from))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $to) => $q->where('work_date', '<=', $to))
            ->when($filters['location_id'] ?? null, fn (Builder $q, $locationId) => $q->where('location_id', (int) $locationId))
            ->orderBy('work_date')
            ->orderBy('start_time')
            ->orderBy('job_postings.id')
            ->paginate($perPage);

        $postings->getCollection()->each(fn (JobPosting $posting) => $this->moveMyApplication($posting));

        return $postings;
    }

    /**
     * F-31 の詳細。募集中かつ勤務日が今日以降、または自分の応募がある募集だけを返す。ほかは 404（ModelNotFoundException）。
     * ルートモデルバインディングは他社の募集を落とすだけなので、締切・中止・過去の募集の可否はここで判定する
     */
    public function findPosting(Worker $worker, JobPosting $posting): JobPosting
    {
        // バインディングで読んだ行をそのまま使い、一覧と同じものを読み込む
        $posting->load($this->relations($worker))->loadCount('confirmedApplications');
        $this->moveMyApplication($posting);

        if (! $posting->isListedForWorkers() && $posting->getRelation('myApplication') === null) {
            throw (new ModelNotFoundException)->setModel(JobPosting::class, [$posting->id]);
        }

        return $posting;
    }

    /**
     * F-33 自分の応募の一覧。勤務日 → 開始時刻 → 応募の ID の昇順（同じ勤務の時刻なら ID で並びを固定する）。
     * $status が null なら絞り込まない。勤務日が過ぎた応募・却下・キャンセルも自分のものなら出す。
     *
     * 企業はグローバルスコープ（applications と、並びの副問い合わせの job_postings の両方に効く）、
     * ワーカーは worker_id で絞る。並びは join ではなく JobPosting の副問い合わせにして、
     * 「join した側のテーブルにスコープが効かない」穴を作らない（security.md）。
     * WorkerApplicationResource が使う jobPosting.location は eager load する（N+1 にしない）
     *
     * @return LengthAwarePaginator<int, Application>
     */
    public function applications(Worker $worker, ?ApplicationStatus $status, int $perPage): LengthAwarePaginator
    {
        $postingColumn = fn (string $column) => JobPosting::query()
            ->select($column)
            ->whereColumn('job_postings.id', 'applications.job_posting_id');

        return Application::query()
            ->where('applications.worker_id', $worker->id)
            ->when($status, fn (Builder $q, ApplicationStatus $s) => $q->where('applications.status', $s))
            ->with('jobPosting.location')
            ->orderBy($postingColumn('work_date'))
            ->orderBy($postingColumn('start_time'))
            ->orderBy('applications.id')
            ->paginate($perPage);
    }

    /**
     * Resource が使う関連。応募はこのワーカーのものだけに絞る（UNIQUE (job_posting_id, worker_id) なので 0 か 1 件）
     *
     * @return array<int|string, mixed>
     */
    private function relations(Worker $worker): array
    {
        return ['location', 'applications' => fn ($q) => $q->where('worker_id', $worker->id)];
    }

    /**
     * 絞って読み込んだ applications を myApplication という名前に移す。
     * applications のまま残すと「全員の応募」と読み違えやすく、Resource は myApplication がなければ LogicException で気づける。
     * JobPosting に HasOne の myApplication を定義しないのは、絞り込みなしで遅延読み込みされると、ほかのワーカーの応募を
     * 「自分の応募」として返してしまうため（読み込みの絞り込みを忘れても漏れない形を選ぶ）
     */
    private function moveMyApplication(JobPosting $posting): void
    {
        $posting->setRelation('myApplication', $posting->applications->first());
        $posting->unsetRelation('applications');
    }
}
