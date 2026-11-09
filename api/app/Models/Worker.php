<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\WorkerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * ワーカー（F-02〜F-04）。削除せず is_active で無効化する（要件の非機能要件）。
 * company_id は #[Fillable] に入れない（BelongsToCompany が CurrentCompany::id() で埋める）。
 * ログイン用の列（password・login_email・registered_at。ワーカー Step 5、docs/01 の 3.1・5.3）も #[Fillable] に入れない。
 * 入れるのは初回登録のサービス（WorkerAuthService）だけで、forceFill() で入れる。password は #[Hidden] で JSON に出さない。
 *
 * ワーカーアプリ（mobile/）のログインの主体なので Authenticatable を継承し、Sanctum の API トークンを持つ（HasApiTokens。docs/01 の 5.3）。
 * Rails で言えば has_secure_password を持つモデルに、API トークンの has_many を足した形。guard は worker（config/auth.php）。
 * https://readouble.com/laravel/13.x/ja/sanctum.html#api-token-authentication
 */
#[Fillable(['name', 'email', 'phone', 'is_active'])]
#[Hidden(['password'])]
class Worker extends Authenticatable
{
    use BelongsToCompany;
    use HasApiTokens;

    /** @use HasFactory<WorkerFactory> */
    use HasFactory;

    /**
     * DB の既定値と揃える（create() 直後のメモリ上の値が null にならないように。docs/01 のモデル）
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'hashed',
            'registered_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * 招待（送り直すたびに増える。使えるのは WorkerInvitation::isUsable() のものだけ）
     *
     * @return HasMany<WorkerInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkerInvitation::class);
    }

    /**
     * 今使える招待（未使用・未取消・期限が未来。条件は WorkerInvitation::scopeUsable()）。
     * 発行のたびに前の招待を取り消すので普通は 0 か 1 件。念のため一番新しいもの（id の最大）を 1 件にする。
     * 一覧では with('usableInvitation') で先読みする（N+1 にしない）。Rails の has_one + scope の先読み（includes）に相当。
     * https://readouble.com/laravel/13.x/ja/eloquent-relationships.html#advanced-has-one-of-many-relationships
     *
     * @return HasOne<WorkerInvitation, $this>
     */
    public function usableInvitation(): HasOne
    {
        return $this->hasOne(WorkerInvitation::class)->ofMany(['id' => 'max'], function (Builder $query) {
            $query->usable();
        });
    }

    /**
     * 招待を送れるか（未登録かつ有効）。WorkerResource の can.invite が使う。
     * WorkerInvitationService::issue() のガードと同じ条件（サービスは理由ごとに文言を分けて投げるので if のまま）。
     * 条件を変えるときは両方を直す
     */
    public function canBeInvited(): bool
    {
        return $this->registered_at === null && $this->is_active;
    }

    /**
     * 氏名の部分一致（F-04）。Rails の scope :name_like に相当し、Worker::query()->nameLike('山田') で呼ぶ。
     * SQLite の LIKE は既定のエスケープ文字がないので ESCAPE を明示し、% と _ を文字として扱う。
     * 列は qualifyColumn で workers.name にする（join しても曖昧にならない）。
     * https://readouble.com/laravel/13.x/ja/eloquent.html#local-scopes
     *
     * @param  Builder<Worker>  $query
     */
    public function scopeNameLike(Builder $query, string $keyword): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword);

        $query->whereRaw($query->qualifyColumn('name')." LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
    }
}
