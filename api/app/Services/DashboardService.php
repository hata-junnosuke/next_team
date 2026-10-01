<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\JobPostingStatus;
use App\Models\Application;
use App\Models\JobPosting;

/**
 * ダッシュボード（F-19、docs/01 6.8）。
 * 戻り値は 6.8 の data の形そのままの配列（P4-07 でこの配列をキャッシュに入れる。キーは dashboard:{company_id}）。
 * 企業はグローバルスコープ（CurrentCompany::id()）で絞られる。
 */
class DashboardService
{
    /** 直近の募集の最大件数 */
    public const UPCOMING_LIMIT = 20;

    /**
     * @return array{
     *     upcoming_postings: list<array{id: int, work_date: string, start_time: string, end_time: string, job_title: string,
     *         location_name: string, capacity: int, confirmed_count: int, fill_rate: int}>,
     *     pending_applications_count: int,
     *     generated_at: string,
     * }
     */
    public function summary(): array
    {
        // 拠点は with、確定数は withCount で一緒に読む（募集の件数によらずクエリ数が一定。N+1 にしない）。
        // work_date は "Y-m-d" の文字列で保存しているので、今日の日付の文字列と比べる（whereDate は使わない）
        $postings = JobPosting::query()
            ->with('location:id,name')
            ->withCount('confirmedApplications')
            ->whereIn('status', [JobPostingStatus::Open, JobPostingStatus::Closed])
            ->where('work_date', '>=', today()->toDateString())
            ->orderBy('work_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->limit(self::UPCOMING_LIMIT)
            ->get();

        return [
            'upcoming_postings' => $postings->map(fn (JobPosting $posting) => [
                'id' => $posting->id,
                'work_date' => $posting->work_date->toDateString(),
                // time 列は書き方によって "09:00:00" で返ることがあるので "09:00" にそろえる
                'start_time' => substr((string) $posting->start_time, 0, 5),
                'end_time' => substr((string) $posting->end_time, 0, 5),
                'job_title' => $posting->job_title,
                'location_name' => $posting->location->name,
                'capacity' => $posting->capacity,
                'confirmed_count' => (int) $posting->confirmed_applications_count,
                'fill_rate' => self::fillRate((int) $posting->confirmed_applications_count, $posting->capacity),
            ])->all(),
            'pending_applications_count' => Application::query()->where('status', ApplicationStatus::Applied)->count(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * 充足率（整数の％）。round() は .5 を 0 から遠い方へ丸める（12.5 → 13）。
     * ただし確定数が定員に届いていなければ上限 99（199/200 = 99.5 を 100% と出すと満員に見えるため。リードの決定）。
     * 定員は検証で 1 以上なので 0 除算は起きない前提（docs/01 6.4）
     * https://www.php.net/manual/ja/function.round.php
     */
    private static function fillRate(int $confirmed, int $capacity): int
    {
        $rate = (int) round($confirmed * 100 / $capacity);

        return $confirmed < $capacity ? min($rate, 99) : $rate;
    }
}
