<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\JobPostingStatus;
use App\Models\Application;
use App\Models\JobPosting;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * ダッシュボード（F-19、docs/01 の API 契約（ダッシュボード））。
 * 戻り値は docs/01 のダッシュボード API の data の形そのままの配列。この配列をキャッシュに入れる（docs/01 の非同期の契約）。
 * 企業はグローバルスコープ（CurrentCompany::id()）で絞られる。キャッシュのキーにも企業ID を入れる（入れないと A 社の値が B 社に見える）。
 *
 * キャッシュ（Rails の Rails.cache.fetch に相当）: https://readouble.com/laravel/13.x/ja/cache.html
 * - 取得: Cache::remember("dashboard:{company_id}", 有効期限, 集計)
 * - 破棄: forget() の 1 箇所だけ。呼ぶのは DashboardCacheObserver（Application / JobPosting / Location の保存・削除のコミット後）だけ
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
        // 「今日」と生成時刻を 1 回だけ決め、集計・有効期限・generated_at で同じ値を使う（途中で日付が変わってもずれない）
        $now = CarbonImmutable::now();

        return Cache::remember(self::cacheKey(CurrentCompany::id()), self::expiresAt($now), fn () => $this->build($now));
    }

    /**
     * 企業のキャッシュを消す。呼ぶのは DashboardCacheObserver だけ（サービスの各メソッドに書くと、新しい経路で漏れる）。
     * 企業は変更されたモデルの company_id を受け取る（モデルの持ち主の企業のキャッシュを消すため）。
     * キーに日付を含めない形にしたので、消すのはこの 1 キーで「今日の分」が確実に消える。
     */
    public function forget(int $companyId): void
    {
        Cache::forget(self::cacheKey($companyId));
    }

    private static function cacheKey(int $companyId): string
    {
        return "dashboard:{$companyId}";
    }

    /**
     * 有効期限は「設定の秒数後」と「翌日 0:00」の早い方。
     *
     * 日付をまたいだ扱い（レビューの申し送り）: upcoming_postings は生成時点の「今日以降」なので、
     * 23:58 に作ったキャッシュを 0:01 に返すと昨日の募集が残る。対策は 2 案あり、こちらを選んだ:
     * - 採用: キーは dashboard:{company_id} のまま、有効期限を日付の終わりまでに制限する。
     *   キーが契約（docs/01 の非同期の契約）どおりで、forget() は 1 キーを消すだけで済む（日付を計算して消し漏れる心配がない）
     * - 不採用: キーに日付を含める（dashboard:{company_id}:{Y-m-d}）。日付が変われば別キーになるが、
     *   forget() が「どの日のキーを消すか」を知る必要があり、0:00 をまたいだ破棄で前日のキーが残る
     * 日付は app.timezone（Asia/Tokyo）で決まる。now() / today() と同じ。
     */
    private static function expiresAt(CarbonImmutable $now): CarbonImmutable
    {
        $bySetting = $now->addSeconds((int) config('myteam.dashboard_cache_seconds'));
        $endOfToday = $now->addDay()->startOfDay();

        return $bySetting->min($endOfToday);
    }

    /**
     * @return array{
     *     upcoming_postings: list<array{id: int, work_date: string, start_time: string, end_time: string, job_title: string,
     *         location_name: string, capacity: int, confirmed_count: int, fill_rate: int}>,
     *     pending_applications_count: int,
     *     generated_at: string,
     * }
     */
    private function build(CarbonImmutable $now): array
    {
        // 拠点は with、確定数は withCount で一緒に読む（募集の件数によらずクエリ数が一定。N+1 にしない）。
        // work_date は "Y-m-d" の文字列で保存しているので、今日の日付の文字列と比べる（whereDate は使わない）
        $postings = JobPosting::query()
            ->with('location:id,name')
            ->withCount('confirmedApplications')
            ->whereIn('status', [JobPostingStatus::Open, JobPostingStatus::Closed])
            ->where('work_date', '>=', $now->toDateString())
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
            // キャッシュした時刻（取得した時刻ではない）。画面で「いつの値か」が分かる
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * 充足率（整数の％）。round() は .5 を 0 から遠い方へ丸める（12.5 → 13）。
     * ただし確定数が定員に届いていなければ上限 99（199/200 = 99.5 を 100% と出すと満員に見えるため。リードの決定）。
     * 定員は検証で 1 以上なので 0 除算は起きない前提（docs/01 の API 契約（募集））
     * https://www.php.net/manual/ja/function.round.php
     */
    private static function fillRate(int $confirmed, int $capacity): int
    {
        $rate = (int) round($confirmed * 100 / $capacity);

        return $confirmed < $capacity ? min($rate, 99) : $rate;
    }
}
