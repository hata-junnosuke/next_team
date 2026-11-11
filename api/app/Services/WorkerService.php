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
     * 同じ企業のほかのワーカー（登録済みかを問わない）とメールが重なる（create() と update() の 422。文言はこの 1 か所）
     */
    public const EMAIL_TAKEN = 'このメールアドレスは、この企業の別のワーカーに使われています。';

    /**
     * 登録時は常に有効（Worker の $attributes の既定値）。company_id は BelongsToCompany が CurrentCompany::id() で埋める。
     * email はモデルのミューテタが小文字にして保存する。重なりの確認は同じ小文字（Worker::normalizeEmail()）で引く。同じ企業のほかのワーカーと重なれば 422
     *
     * @param  array{name: string, email: string, phone?: string|null}  $data
     */
    public function create(array $data): Worker
    {
        return DB::transaction(function () use ($data) {
            $this->ensureEmailIsFree(Worker::normalizeEmail($data['email']));

            $worker = new Worker($data);
            $this->saveOrFailOnTakenEmail($worker);

            $this->invitations->issue($worker);

            return $worker;
        });
    }

    /**
     * 編集（F-02）。email はモデルのミューテタが小文字にして保存する。メールが変わったとき（docs/01 の 3.1・4.5）:
     * - 新しいメールが同じ企業のほかのワーカー（登録済み・未登録を問わない）と重なれば 422
     * - 登録済みならそのまま（次のログインから新しいメールで入る）
     * - 未登録なら使える招待を取り消し、有効なら新しいメール宛に招待を発行する（無効なら取り消すだけ。有効に戻したら F-27 で送り直す）
     *
     * 「変わったか」は小文字にそろえて比べる。保存も照合も小文字なので、大文字小文字だけの違いなら
     * 前の招待のコードはそのまま使え、送り直す必要がない。
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

            // 保存済みの email はミューテタで常に小文字なので、入力だけを小文字にして比べる
            $newEmail = Worker::normalizeEmail($data['email']);
            $emailChanged = $newEmail !== $locked->email;

            if ($emailChanged) {
                $this->ensureEmailIsFree($newEmail, $locked);
            }

            $locked->fill($data);
            $this->saveOrFailOnTakenEmail($locked);

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
     * 同じ企業のほかのワーカーとメールが重ならないか。企業のグローバルスコープが効いているので自社の中だけを見る
     * （他社のワーカーの有無は分からない。FormRequest の unique にしないのも同じ理由）
     */
    private function ensureEmailIsFree(string $email, ?Worker $except = null): void
    {
        $taken = Worker::query()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->where('email', $email)
            ->exists();

        if ($taken) {
            throw new DomainException(self::EMAIL_TAKEN, 'email');
        }
    }

    /**
     * 保存する。確認の後に同じメールのワーカーが別のリクエストで保存された（UNIQUE (company_id, email) の違反）ときも、確認と同じ 422 にする。
     * workers の UNIQUE はこれだけである前提。投げ直すので外側のトランザクションごとロールバックする（続けるならセーブポイントで包む）
     */
    private function saveOrFailOnTakenEmail(Worker $worker): void
    {
        try {
            $worker->save();
        } catch (UniqueConstraintViolationException) {
            throw new DomainException(self::EMAIL_TAKEN, 'email');
        }
    }
}
