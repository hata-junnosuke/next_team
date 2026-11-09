<?php

namespace App\Services;

use App\Events\WorkerInvited;
use App\Exceptions\DomainException;
use App\Models\Worker;
use App\Models\WorkerInvitation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * ワーカーの招待の発行（F-26・F-27。docs/01 の 3.2・4.5）。WorkerService の登録・メールの変更と、送り直しの API から呼ぶ。
 *
 * - ワーカーの行を lockForUpdate() してから判定する。送り直しが同時に 2 回来ても、使える招待が 2 つ残らないように 1 つずつにする
 *   （SQLite は lockForUpdate() を無視するが transaction_mode=IMMEDIATE で直列になる。MySQL に移してもそのまま効くように書く）
 * - 前の未使用・未取消の招待（期限切れを含む）は取り消す（revoked_at を入れ、暗号化した code を NULL にする）。使用済み・取り消し済みの行には触らない
 * - 招待コードは random_int() で 1 文字ずつ選ぶ（推測されにくい暗号論的な乱数）。code_hash が UNIQUE で重なったら作り直す
 * - コミット後に WorkerInvited を発火する（ShouldDispatchAfterCommit）。イベントとジョブには招待の ID だけを渡す
 * https://readouble.com/laravel/13.x/ja/queries.html#pessimistic-locking
 */
class WorkerInvitationService
{
    /** 招待コードが重なったときに作り直す回数の上限 */
    private const MAX_CODE_ATTEMPTS = 5;

    public function issue(Worker $worker): WorkerInvitation
    {
        return DB::transaction(function () use ($worker) {
            // ルートモデルバインディングで読んだ値は古いことがある（直前に無効化・初回登録された等）ので、ロックして読み直す
            $worker = Worker::query()->lockForUpdate()->findOrFail($worker->id);

            // Worker::canBeInvited()（WorkerResource の can.invite）と同じ条件。理由ごとに文言を分けるので if で書く。条件を変えるときは両方を直す
            if (! $worker->is_active) {
                throw new DomainException('無効のワーカーには招待を送れません。');
            }

            if ($worker->registered_at !== null) {
                throw new DomainException('このワーカーはすでに初回登録を済ませています。');
            }

            $this->revokeUsable($worker);

            $invitation = $this->createWithUniqueCode($worker);

            WorkerInvited::dispatch($invitation->id);

            return $invitation;
        });
    }

    /**
     * 前の未使用・未取消の招待（期限切れを含む）を取り消す。revoked_at は状態の列なので #[Fillable] の外（forceFill で入れる）。
     * 期限切れの招待も code を消す（もう要らない秘密を持ち続けない）
     */
    private function revokeUsable(Worker $worker): void
    {
        $worker->invitations()
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            // MySQL の REPEATABLE READ では、ロックなしの読み取りは外側のトランザクションの古いスナップショットを読みうる。
            // ロックつきで読めば常に最新のコミット済みの行を読むので、同時の発行で取り消し漏れが出ない（SQLite では IMMEDIATE で直列）
            ->lockForUpdate()
            ->get()
            ->each(fn (WorkerInvitation $previous) => $previous->forceFill([
                'revoked_at' => now(),
                'code' => null,
            ])->save());
    }

    /**
     * 新しい招待を作る。code_hash（全社で UNIQUE）が他の招待と重なったら、コードを作り直して入れ直す。
     * 重なりの事前確認に企業のスコープを外したクエリを使わず、DB の UNIQUE に判定させる（他社の行を読まない）。
     * 1 回の INSERT を入れ子の DB::transaction（セーブポイント）で包むので、重なって失敗しても外側のトランザクションは続けられる。
     * 約 8.5×10^11 通りなので重なるのはまれ。それでも続けて重なるなら何かがおかしいので、決めた回数で諦めて例外を投げ直す
     */
    private function createWithUniqueCode(Worker $worker): WorkerInvitation
    {
        for ($attempt = 1; ; $attempt++) {
            $code = self::randomCode();

            try {
                return DB::transaction(fn () => WorkerInvitation::create([
                    'worker_id' => $worker->id,
                    'code' => $code,
                    'code_hash' => WorkerInvitation::hashCode($code),
                    'expires_at' => now()->addDays((int) config('myteam.worker_invitation_days')),
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_CODE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * random_int() で 1 文字ずつ選ぶ（推測されにくい暗号論的な乱数。Str::random() は使わない。docs/01 の 3.2）
     */
    private static function randomCode(): string
    {
        $characters = WorkerInvitation::CODE_CHARACTERS;
        $code = '';
        for ($i = 0; $i < WorkerInvitation::CODE_LENGTH; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $code;
    }
}
