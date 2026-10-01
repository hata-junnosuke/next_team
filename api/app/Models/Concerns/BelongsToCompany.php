<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 企業ごとのデータを持つモデルに付ける（Rails の default_scope + before_create に相当）。
 *
 * - グローバルスコープ company: 常に where company_id = CurrentCompany::id()。未設定なら例外（全件を返さない）
 * - creating: company_id を CurrentCompany::id() で埋める（リクエストの値は使わない）
 *
 * ルートモデルバインディングもこのスコープを通るので、他社の ID は 404 になる。
 * https://readouble.com/laravel/13.x/ja/eloquent.html#global-scopes
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $builder->where($builder->qualifyColumn('company_id'), CurrentCompany::id());
        });

        static::creating(function (Model $model) {
            $model->setAttribute('company_id', CurrentCompany::id());
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
