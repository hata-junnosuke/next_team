<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\WorkerInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ワーカーの招待（F-26〜F-28。docs/01 の 3.2）。作る・使用済みにする・取り消すのは WorkerInvitationService と WorkerAuthService。
 * - code_hash: 招待コードの HMAC（hashCode()）。初回登録のときにこれで行を引く。bcrypt は毎回違う値になるので引けない
 * - code: 暗号化した招待コード（encrypted cast。Rails の encrypts に相当）。メールに書くためだけに持つ
 * used_at と revoked_at は状態の列なので #[Fillable] に入れない（サービスが forceFill() で入れる）。
 * code と code_hash は #[Hidden] で配列（JSON・ログ）に出さない。
 * https://readouble.com/laravel/13.x/ja/eloquent-mutators.html#encrypted-casting
 */
#[Fillable(['worker_id', 'code_hash', 'code', 'expires_at'])]
#[Hidden(['code_hash', 'code'])]
class WorkerInvitation extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<WorkerInvitationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * 招待コードから code_hash を作る（発行と初回登録の 1 箇所）。
     * 入力は前後の空白を除いて大文字に直してから計算する（スマホで小文字が入っても通るように）。
     * 鍵は用途の接頭辞つきの APP_KEY（暗号化の鍵と分ける）。APP_KEY を入れ替えると発行済みのコードは使えなくなる。
     */
    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', strtoupper(trim($code)), 'worker-invitation:'.config('app.key'));
    }

    /**
     * 使える招待か（未使用・未取消・期限が未来）。判定はこの 1 箇所に置く（docs/01 の 3.2）
     */
    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * 同じ企業でなければならない親（BelongsToCompany が保存時に確かめる）
     *
     * @return array<string, class-string<Model>>
     */
    public function companyScopedParents(): array
    {
        return ['worker_id' => Worker::class];
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
