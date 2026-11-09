<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\WorkerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ワーカー（F-02〜F-04）。削除せず is_active で無効化する（要件の非機能要件）。
 * company_id は #[Fillable] に入れない（BelongsToCompany が CurrentCompany::id() で埋める）。
 * ログイン用の列（password・login_email・registered_at。ワーカー Step 5、docs/01 の 3.1・5.3）も #[Fillable] に入れない。
 * 入れるのは初回登録のサービス（WorkerAuthService）だけで、forceFill() で入れる。password は #[Hidden] で JSON に出さない。
 */
#[Fillable(['name', 'email', 'phone', 'is_active'])]
#[Hidden(['password'])]
class Worker extends Model
{
    use BelongsToCompany;

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
