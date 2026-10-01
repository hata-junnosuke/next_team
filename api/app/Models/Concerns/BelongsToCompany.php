<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * 企業ごとのデータを持つモデルに付ける（Rails の default_scope + before_create に相当）。
 *
 * - グローバルスコープ company: 常に where company_id = CurrentCompany::id()。未設定なら例外（全件を返さない）
 * - creating: company_id を CurrentCompany::id() で埋める（リクエストの値は使わない）
 * - updating: company_id の変更は LogicException（他社への付け替えを基盤で防ぐ）
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

        // 一度決まった所属企業は変えない。#[Fillable] から外していても forceFill や直接代入で変えられるため、保存の直前で止める
        static::updating(function (Model $model) {
            if ($model->isDirty('company_id')) {
                throw new LogicException('company_id は変更できません（企業をまたいだ付け替えは禁止）。');
            }
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
