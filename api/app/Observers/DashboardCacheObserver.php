<?php

namespace App\Observers;

use App\Services\DashboardService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * ダッシュボードのキャッシュを破棄する Observer（F-19。docs/01 8 章、docs/03 3.5）。Rails の after_commit コールバックに相当。
 * Application と JobPosting と Location に付ける（AppServiceProvider::boot() の observe()）。
 * Location はダッシュボードに拠点名（location_name）を出しているため。
 *
 * - ShouldHandleEventsAfterCommit: トランザクションのコミット後に動く。ロールバックされたら動かない。
 *   途中で消すと、別のリクエストがコミット前の古い値でキャッシュを作り直してしまうため
 * - 破棄を Observer に置くのは、モデルが変われば経路によらず必ず消えるようにするため（サービスごとに書くと漏れる）
 * - 一括の query()->update() はモデルのイベントを出さないので、ここを通らない。サービスはモデルの save() で変えること
 *
 * https://readouble.com/laravel/13.x/ja/eloquent.html#observers
 */
class DashboardCacheObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function saved(Model $model): void
    {
        $this->forget($model);
    }

    public function deleted(Model $model): void
    {
        $this->forget($model);
    }

    /**
     * 変更されたモデルの持ち主の企業のキャッシュを消す（company_id は BelongsToCompany が CurrentCompany::id() で埋めた値）
     */
    private function forget(Model $model): void
    {
        $companyId = $model->getAttribute('company_id');

        // 列を絞って読んだモデル（select で company_id を外した）だと null になる。(int) で 0 にして dashboard:0 を消すと、
        // 本当に消すべき企業のキャッシュが残ったまま気づけないので、report() で記録だけして何もしない（保存自体は止めない）。
        // 残ったキャッシュは有効期限（myteam.dashboard_cache_seconds、最長で翌日 0:00）で切れる。
        // https://readouble.com/laravel/13.x/ja/errors.html#the-report-helper
        if ($companyId === null) {
            report(new LogicException(class_basename($model).' の company_id が読まれていないため、ダッシュボードのキャッシュを破棄できません（id: '.$model->getKey().'）。'));

            return;
        }

        $this->dashboard->forget((int) $companyId);
    }
}
