<?php

namespace Tests\Concerns;

use App\Models\Worker;
use DateTimeInterface;
use Laravel\Sanctum\Sanctum;

/**
 * ワーカーとしてログインした状態を作る（docs/01 の 9.4）。tests/TestCase.php は基盤の所有でロック後は触らないので、トレイトに置く。
 *
 * - actingAsWorker(): Sanctum::actingAs() で guard worker にワーカーを入れる（業務の機能のテスト用。手軽だが guard の provider の
 *   確認（hasValidProvider）とトークンの期限を通らない）
 * - workerToken(): 本物のトークンを発行して平文を返す。守り（5.3 の「入れないことの確認」）のテストはこちらで書き、withToken() で付ける
 *
 * Rails で言えば request spec の sign_in ヘルパーに当たる。
 * https://readouble.com/laravel/13.x/ja/sanctum.html#testing
 */
trait ActsAsWorker
{
    protected function actingAsWorker(Worker $worker): static
    {
        Sanctum::actingAs($worker, ['worker'], 'worker');

        return $this;
    }

    /**
     * 本物のトークン（personal_access_tokens の行）を発行し、Bearer に付ける平文（"ID|乱数"）を返す。
     * 既定は本番と同じ能力 ['worker'] と期限（config('myteam.worker_token_days') 日後）
     *
     * @param  list<string>  $abilities
     */
    protected function workerToken(
        Worker $worker,
        array $abilities = ['worker'],
        ?DateTimeInterface $expiresAt = null,
        string $deviceName = 'iPhone 16',
    ): string {
        $expiresAt ??= now()->addDays((int) config('myteam.worker_token_days'));

        return $worker->createToken($deviceName, $abilities, $expiresAt)->plainTextToken;
    }

    /**
     * 1 つのテストの中で続けてリクエストを送るとき、前のリクエストで認証済みのユーザーが guard に残らないようにする。
     * テストではアプリのインスタンスを使い回すので、RequestGuard が覚えたユーザーを消さないと、ログアウトや無効化の後も
     * 前のワーカーのまま通ってしまう（本番は 1 リクエストごとに作り直される）
     */
    protected function forgetAuthenticatedUsers(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * 同じテストの中でワーカーから管理者に切り替える（ワーカーの操作が管理画面にどう出るかを確かめるとき）。
     * Sanctum::actingAs(..., 'worker') は既定の guard を worker にする（shouldUse）ので、そのまま actingAsAdmin() すると
     * 管理者が worker の guard に入って 401 になる。guard を忘れさせ、既定を web に戻してから管理者でログインする
     */
    protected function switchToAdmin(): static
    {
        $this->forgetAuthenticatedUsers();
        // config('auth.defaults.guard') は actingAsWorker() の shouldUse で worker に書き換わっているので読めない。web を直接書く
        $this->app['auth']->shouldUse('web');

        return $this->actingAsAdmin();
    }
}
