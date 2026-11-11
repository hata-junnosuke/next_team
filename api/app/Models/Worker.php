<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\WorkerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * ワーカー（F-02〜F-04）。削除せず is_active で無効化する（要件の非機能要件）。
 * company_id は #[Fillable] に入れない（BelongsToCompany が CurrentCompany::id() で埋める）。
 * ログイン用の列（password・registered_at。ワーカー Step 5、docs/01 の 3.1・5.3）も #[Fillable] に入れない。
 * 入れるのは WorkerAuthService（初回登録と、ログインの成功時に古い設定の password を今の設定で作り直すとき）だけで、forceFill() で入れる。password は #[Hidden] で JSON に出さない。
 * email はログインの識別子を兼ね、同じ企業の中で一意（UNIQUE (company_id, email)）。email のミューテタが小文字（normalizeEmail()）にして保存する。
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
     * email は代入のたびに小文字（normalizeEmail()）にする。Rails の normalizes :email に相当。
     * サービス・ファクトリ・シーダー・forceFill のどの経路で保存しても、UNIQUE (company_id, email) と照合が小文字の値で効く（docs/01 の 3.1）。
     * クエリビルダの update()（Worker::query()->update([...])）はモデルを通らないので効かない。メールをそれで書き換えない
     * https://readouble.com/laravel/13.x/ja/eloquent-mutators.html#defining-a-mutator
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => self::normalizeEmail($value),
        );
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
     * メール（email）の形にそろえる（docs/01 の 3.1。メールは小文字にして保存し、照合も小文字で行う）。
     * **メールの小文字化はここだけ。** 呼び出し元: email のミューテタ（保存）、WorkerService（重なりの確認）、
     * WorkerAuthService（初回登録とログインの照合）、AppServiceProvider（ワーカーのレート制限のキー）（関数が混ざると、非 ASCII の文字で照合がずれる）。
     * マイグレーション（2026_11_09_100100）はモデルに頼らないため、同じ mb_strtolower を直接使う。
     * 非 ASCII の文字も小文字にするので mb_strtolower。前後の空白はここでは落とさない（HTTP の入力は既定のミドルウェア TrimStrings が
     * 先に落としている。Rails で言えば squish 済みの params を受け取る形）
     * https://www.php.net/manual/ja/function.mb-strtolower.php
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower($email);
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
