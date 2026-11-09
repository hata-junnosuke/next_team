<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Worker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * ワーカーの登録と編集（F-02。docs/01 の 4.5）。登録すると招待を発行する（F-26）。
 *
 * - 登録と招待の発行は同じトランザクション。どちらかが失敗したら両方なかったことにする
 * - 招待メールのジョブは WorkerInvited（ShouldDispatchAfterCommit）がコミットの後に積む。応答はメールの送信を待たない
 * - メールを直したときの扱い（docs/01 の 3.1）は update() に書く
 * https://readouble.com/laravel/13.x/ja/database.html#database-transactions
 */
class WorkerService
{
    public function __construct(private readonly WorkerInvitationService $invitations) {}

    /**
     * 登録時は常に有効（Worker の $attributes の既定値）。company_id は BelongsToCompany が CurrentCompany::id() で埋める
     *
     * @param  array{name: string, email: string, phone?: string|null}  $data
     */
    public function create(array $data): Worker
    {
        return DB::transaction(function () use ($data) {
            $worker = Worker::create($data);

            $this->invitations->issue($worker);

            return $worker;
        });
    }

    /**
     * 編集（F-02）。メールが変わったとき（docs/01 の 3.1・4.5）:
     * - 新しいメールが同じ企業のほかの登録済みワーカーの login_email と重なれば 422（登録済み・未登録を問わない。未登録でも初回登録が必ず失敗するため）
     * - 登録済みなら login_email を小文字で追従させる
     * - 未登録なら使える招待を取り消し、有効なら新しいメール宛に招待を発行する（無効なら取り消すだけ。有効に戻したら F-27 で送り直す）
     *
     * 「変わったか」は大文字小文字を無視して比べる。ログインと初回登録の照合は小文字で行うので、大文字小文字だけの違いなら
     * 前の招待のコードはそのまま使え、送り直す必要がない（email の列には入力どおりの値を入れ、login_email は常に Worker::normalizeLoginEmail() でそろえる）。
     *
     * ロックの順は WorkerInvitationService::issue() と同じ「ワーカー → 招待」。ワーカーの行をロックして読み直してから判定する
     * （ルートモデルバインディングで読んだ値は古いことがある。直前に初回登録された・無効にされた等）。
     *
     * @param  array{name: string, email: string, phone?: string|null}  $data
     */
    public function update(Worker $worker, array $data): Worker
    {
        return DB::transaction(function () use ($worker, $data) {
            $locked = Worker::query()->lockForUpdate()->findOrFail($worker->id);
            $registered = $locked->registered_at !== null;

            $newLoginEmail = Worker::normalizeLoginEmail($data['email']);
            $emailChanged = $newLoginEmail !== Worker::normalizeLoginEmail($locked->email);

            if ($emailChanged) {
                $this->ensureLoginEmailIsFree($locked, $newLoginEmail);
            }

            $locked->fill($data);
            if ($registered) {
                // login_email は #[Fillable] の外（ログイン用の列。docs/01 の 3.1）なので forceFill で入れる
                $locked->forceFill(['login_email' => $newLoginEmail]);
            }

            try {
                $locked->save();
            } catch (UniqueConstraintViolationException) {
                // 確認の後に、同じメールで別のワーカーが初回登録を済ませた。workers の UNIQUE は (company_id, login_email) だけである前提で、同じ文言の 422 にする。
                // 投げ直すので外側ごとロールバックする。続けるならセーブポイント（入れ子の DB::transaction）で包む
                throw new DomainException(WorkerAuthService::EMAIL_TAKEN, 'email');
            }

            // 招待を扱うのは「メールが変わった未登録のワーカー」だけ
            if (! $emailChanged || $registered) {
                return $locked;
            }

            if ($locked->is_active) {
                $this->invitations->issue($locked);
            } else {
                $this->invitations->revoke($locked);
            }

            return $locked;
        });
    }

    /**
     * 同じ企業のほかの登録済みワーカーと login_email が重ならないか。企業のグローバルスコープが効いているので自社の中だけを見る
     * （他社の登録状況は分からない。FormRequest の unique にしないのも同じ理由）
     */
    private function ensureLoginEmailIsFree(Worker $worker, string $loginEmail): void
    {
        $taken = Worker::query()
            ->whereKeyNot($worker->id)
            ->where('login_email', $loginEmail)
            ->exists();

        if ($taken) {
            throw new DomainException(WorkerAuthService::EMAIL_TAKEN, 'email');
        }
    }
}
