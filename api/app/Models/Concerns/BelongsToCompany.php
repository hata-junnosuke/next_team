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
 * - saving: companyScopedParents() で宣言した親の外部キーが他社の行を指していたら LogicException
 *   （例: 他社の拠点 ID を location_id に入れた募集。FormRequest の exists 検査の漏れを基盤で止める最後の砦）
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

        static::saving(function (Model $model) {
            /** @var array<string, class-string<Model>> $parents */
            $parents = $model->companyScopedParents();

            foreach ($parents as $foreignKey => $parentClass) {
                $id = $model->getAttribute($foreignKey);
                // 作成時は必ず、更新時は外部キーが変わったときだけ確かめる
                if ($id === null || ($model->exists && ! $model->isDirty($foreignKey))) {
                    continue;
                }

                // 他社の親かどうかを見分けるため、グローバルスコープを外して親の company_id を読む（読むのはこの 1 列だけ）。
                // 親が存在しないときは何もしない（外部キー制約が QueryException で止める）
                $parentCompanyId = $parentClass::query()->withoutGlobalScope('company')->whereKey($id)->value('company_id');

                if ($parentCompanyId !== null && (int) $parentCompanyId !== CurrentCompany::id()) {
                    throw new LogicException(class_basename($model)." の {$foreignKey} に他社の行は指定できません。");
                }
            }
        });
    }

    /**
     * 同じ企業でなければならない親の外部キー（['location_id' => Location::class]）。モデルで上書きして宣言する。
     *
     * @return array<string, class-string<Model>>
     */
    public function companyScopedParents(): array
    {
        return [];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
