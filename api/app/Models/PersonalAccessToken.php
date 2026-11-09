<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum の API トークン（ワーカーアプリのログイン。docs/01 の 5.3）。AppServiceProvider で
 * Sanctum::usePersonalAccessTokenModel() に渡して、Sanctum の既定のモデルの代わりに使う。
 * https://readouble.com/laravel/13.x/ja/sanctum.html#overriding-default-models
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * トークンの持ち主（今はワーカーだけ。管理者は Cookie の SPA 認証でトークンを持たない）。
     *
     * @return MorphTo<Model, $this>
     */
    public function tokenable(): MorphTo
    {
        // withoutGlobalScope('company') の理由: Sanctum の guard がトークンから持ち主を読む時点では、まだ企業が決まっていない
        // （企業は持ち主のワーカーの company_id から worker ミドルウェアが決める）。Worker のグローバルスコープを残すと
        // CurrentCompany::id() が LogicException になるため、この 1 行（トークンの tokenable_id で引く持ち主）だけスコープを外す。
        // 引くのは主キーとトークンの組み合わせで決まる 1 行なので、他社の行が混ざることはない（docs/01 の 4.2・5.3）
        // 注: MorphTo は eager load（with('tokenable')）のときに withoutGlobalScope（単数形）を再生しないので、外れるのは lazy で読むときだけ
        return $this->morphTo('tokenable')->withoutGlobalScope('company');
    }
}
