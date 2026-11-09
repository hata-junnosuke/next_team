<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Http\Middleware\AuthenticateWorker;
use App\Models\Company;
use App\Models\Worker;
use App\Models\WorkerInvitation;
use App\Support\CurrentCompany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Throwable;

/**
 * ワーカーの認証（F-28・F-29。docs/01 の 4.5・5.3）。Fortify を通さず、Sanctum の公式手順（モバイルアプリケーション認証）で書く。
 * トークンを発行するのはこのサービスだけ（security.md）。Rails で言えば Devise を使わず has_secure_password と
 * API トークンの発行を自前のサービスにまとめた形。
 * https://readouble.com/laravel/13.x/ja/sanctum.html#mobile-application-authentication
 */
class WorkerAuthService
{
    /** 初回登録の ①② の失敗（見つからない・メール不一致・使用済み・取り消し・期限切れを区別しない。アカウントの有無を明かさない） */
    public const INVALID_CODE = '招待コードが正しくないか、有効期限が切れています。';

    /**
     * 同じ企業の登録済みワーカーと login_email が重なる（④）
     * （WorkerService::update() の重なりの 422 もこの定数を使う。文言はこの 1 か所）
     */
    public const EMAIL_TAKEN = 'このメールアドレスは、この企業の別のワーカーのログインに使われています。';

    /** ログインの失敗（企業コード・メール・パスワードのどれが誤りか、未登録かを区別しない。アカウントの有無を明かさない） */
    public const INVALID_CREDENTIALS = '企業コード・メールアドレス・パスワードのいずれかが正しくありません。';

    /**
     * ログインの判定にかける最短の時間（マイクロ秒）。管理者のログイン（SessionGuard の Timebox）と同じ 200 ミリ秒。
     * 本番のコスト（BCRYPT_ROUNDS=12）では照合だけで 200 ミリ秒を超えるので、Timebox は待たない。
     * 時間をそろえているのは、どの道（見つからない・未登録・パスワード誤り・無効・成功）も「パスワードの照合 1 回」と
     * 「ダミーのハッシュのキャッシュの読み取り 1 回」にしていること。Timebox は照合が速い設定（テストのコスト 4 など）での下限
     */
    private const LOGIN_TIMEBOX_MICROSECONDS = 200_000;

    /**
     * 初回登録（F-28）。判定の順と文言は docs/01 の 4.5 のとおり:
     * ① code_hash で招待を引く → CurrentCompany::runAs() ② 使えるか・入力のメールが今の workers.email と小文字で一致するか
     * ③ 無効ワーカーか ④ 同じ企業の登録済みワーカーと login_email が重ならないか。
     * 通れば招待を使用済みにして code を NULL にし、password・login_email・registered_at を入れてトークンを発行する。
     *
     * ロックの順は WorkerInvitationService・WorkerService と同じ「ワーカー → 招待」（逆にするとデッドロックしうる）。
     * そのため ① はロックせずに引いて worker_id と company_id だけを得て、ワーカー → 招待の順にロックして読み直してから判定する。
     *
     * @return array{worker: Worker, token: string}
     */
    public function register(string $email, string $code, string $password, string $deviceName): array
    {
        // 入れ子の順は「外がトランザクション、中が runAs」（① もトランザクションの中で直列にするため）。コミットは runAs を抜けた後なので、
        // コミットの後に動く処理（ShouldDispatchAfterCommit のイベント等）を足すときは CurrentCompany が空になる。足すならこの順を見直す
        return DB::transaction(function () use ($email, $code, $password, $deviceName) {
            // ① 企業が決まる前（認証の外）に招待コードから招待を引くので、企業のスコープを外す（docs/01 の 4.2。外すのはこの 1 行だけ）。
            // 引いたらすぐ、その招待の企業として残りを動かす。ここではロックしない（ロックはワーカー → 招待の順で下で取る）
            $found = WorkerInvitation::withoutGlobalScope('company')
                ->where('code_hash', WorkerInvitation::hashCode($code))
                ->first(['id', 'worker_id', 'company_id']);

            if ($found === null) {
                throw new DomainException(self::INVALID_CODE, 'code');
            }

            return CurrentCompany::runAs(
                $found->company_id,
                fn () => $this->registerInCompany($found, $email, $password, $deviceName),
            );
        });
    }

    /**
     * ログイン（F-29。docs/01 の 4.5）。企業コードを大文字に直して Company を引き（企業にはスコープがない）、
     * CurrentCompany::runAs() でその企業のワーカーを login_email（小文字）で引いて Hash::check() → createToken()。
     * 判定の順: 企業・ワーカー・登録済み・パスワードのどれかが合わない → INVALID_CREDENTIALS。合ったうえで無効 → 無効の文言
     * （無効かどうかはパスワードを知っている人にだけ答える）。
     *
     * 応答時間でアカウントの有無が分からないように、全体を Timebox で包み（Fortify の attempt() と同じ守り）、
     * 見つからない・未登録のときもダミーのハッシュで Hash::check() を 1 回呼ぶ。早く返すのは成功のときだけ。
     * Timebox は早く返すかの状態（earlyReturn）を持つので、呼ぶたびに作る（使い回すと前の成功の状態が残る）。
     * https://readouble.com/laravel/13.x/ja/helpers.html#timebox
     *
     * @return array{worker: Worker, token: string}
     */
    public function login(string $companyCode, string $email, string $password, string $deviceName): array
    {
        return (new Timebox)->call(function (Timebox $timebox) use ($companyCode, $email, $password, $deviceName) {
            $company = Company::query()->where('code', mb_strtoupper($companyCode))->first();

            // ワーカーのスコープは外さない。企業コードで決めた企業として引く
            $worker = $company === null ? null : CurrentCompany::runAs(
                $company->id,
                fn () => Worker::query()
                    ->where('login_email', Worker::normalizeLoginEmail($email))
                    ->whereNotNull('registered_at')
                    ->first(),
            );

            // 見つからない・未登録（password が NULL）でも照合を飛ばさない（飛ばすと速く返り、時間でアカウントの有無が分かる）。
            // ダミーのハッシュは使うかどうかに関係なく毎回キャッシュから読む（読み取りを見つからない道だけにすると、
            // キャッシュが遅い・落ちているときにその道だけ遅くなって分かる）。この道で Hash::make() は呼ばない（作る時間の分だけ遅くなる）
            $dummy = $this->dummyHash();
            $hash = $worker?->password ?? $dummy;
            if (! Hash::check($password, $hash) || $worker === null || $worker->password === null) {
                throw new DomainException(self::INVALID_CREDENTIALS, 'email');
            }

            if (! $worker->is_active) {
                throw new DomainException(AuthenticateWorker::DISABLED_MESSAGE, 'email');
            }

            // コストを上げた後も、古いハッシュの照合の時間がダミー（今の設定）とずれないよう、成功したら今の設定で作り直す
            // （管理者のログインの SessionGuard::rehashPasswordIfRequired() と同じ。password は hashed cast がハッシュにする）
            if (Hash::needsRehash($worker->password)) {
                $worker->forceFill(['password' => $password])->save();
            }

            $token = $this->issueToken($worker, $deviceName);
            $timebox->returnEarly();

            return ['worker' => $worker->load('company'), 'token' => $token];
        }, self::LOGIN_TIMEBOX_MICROSECONDS);
    }

    /**
     * 今のリクエストのトークンだけを消す（ほかの端末のトークンはそのまま。docs/01 の 5.3）
     */
    public function logout(Worker $worker): void
    {
        $worker->currentAccessToken()->delete();
    }

    /**
     * ②〜④ と登録。CurrentCompany は招待の企業になっている（ここから先はスコープを外さない）
     *
     * @return array{worker: Worker, token: string}
     */
    private function registerInCompany(WorkerInvitation $found, string $email, string $password, string $deviceName): array
    {
        $worker = Worker::query()->lockForUpdate()->find($found->worker_id);
        $invitation = WorkerInvitation::query()->lockForUpdate()->find($found->id);

        // ② 読み直した今の値で判定する（①の後に使用済み・取り消し・メールの変更が入りうる）。
        // 登録済みのワーカーは、使える招待が残っていても登録をやり直させない（パスワードを上書きさせない）
        if ($worker === null
            || $invitation === null
            || ! $invitation->isUsable()
            || $worker->registered_at !== null
            || Worker::normalizeLoginEmail($email) !== Worker::normalizeLoginEmail($worker->email)) {
            throw new DomainException(self::INVALID_CODE, 'code');
        }

        // ③
        if (! $worker->is_active) {
            throw new DomainException(AuthenticateWorker::DISABLED_MESSAGE, 'email');
        }

        // ④ 企業のグローバルスコープが効いているので自社の中だけを見る（他社に同じメールの登録済みワーカーがいてもよい）
        $loginEmail = Worker::normalizeLoginEmail($worker->email);
        $taken = Worker::query()
            ->whereKeyNot($worker->id)
            ->where('login_email', $loginEmail)
            ->exists();

        if ($taken) {
            throw new DomainException(self::EMAIL_TAKEN, 'email');
        }

        // used_at と code は状態の列・秘密なので #[Fillable] の外（forceFill で入れる）。使ったコードはもう持たない
        $invitation->forceFill(['used_at' => now(), 'code' => null])->save();

        // ログイン用の列は #[Fillable] の外（docs/01 の 5.3）。password は hashed cast がハッシュにする
        $worker->forceFill([
            'password' => $password,
            'login_email' => $loginEmail,
            'registered_at' => now(),
        ]);

        try {
            $worker->save();
        } catch (UniqueConstraintViolationException) {
            // ④ の確認の後に、同じメールで別のワーカーが登録を済ませた。workers の UNIQUE は (company_id, login_email) だけである前提で、
            // ④ と同じ文言の 422 にする。投げ直すので招待の使用済みも含めてロールバックする
            throw new DomainException(self::EMAIL_TAKEN, 'email');
        }

        $token = $this->issueToken($worker, $deviceName);

        return ['worker' => $worker->load('company'), 'token' => $token];
    }

    /**
     * トークンを発行して平文を返す（初回登録とログインの 2 か所から。発行はこのサービスだけ）。
     * 能力は worker ちょうど（worker ミドルウェアが tokenCan('worker') を確かめる）。期限は設定の日数後（期限なしを出さない）
     */
    private function issueToken(Worker $worker, string $deviceName): string
    {
        return $worker->createToken(
            $deviceName,
            ['worker'],
            now()->addDays((int) config('myteam.worker_token_days')),
        )->plainTextToken;
    }

    /**
     * ダミーのハッシュを置くキャッシュのキー。ハッシュのドライバと設定（bcrypt はコスト、argon はメモリ・時間・スレッド）を含める。
     * 設定を変えたら別のキーになり、古い設定のダミー（照合の時間が本物とずれる）を使わない。
     * 企業のデータではない（全社で共通の照合用の値）ので企業ID は含めない
     */
    public static function dummyHashCacheKey(): string
    {
        $driver = (string) config('hashing.driver');
        $options = Arr::only((array) config("hashing.{$driver}", []), ['rounds', 'memory', 'time', 'threads']);

        return 'worker-login:dummy-hash:'.$driver.':'.implode('-', $options);
    }

    /**
     * 照合用のダミーのハッシュ。今のハッシャーの設定（アルゴリズム・コスト）で作るので、本物の照合と同じだけ時間がかかる。
     * 元の文字列は捨てるので、どの入力とも合わない。
     * 本番（PHP-FPM）はリクエストごとにプロセスの static が消えるので、キャッシュに置いて作るのは最初の 1 回だけにする。
     * キャッシュが使えないとき（接続の失敗など）は、その場で作ってログインを続け、例外は報告する（ログインを 500 で止めない）。
     * login() はどの道でもこれを 1 回呼ぶので、障害の間も読み取りの待ちとその場の作成はすべての道で同じだけかかる（障害は報告で気づける）
     * https://readouble.com/laravel/13.x/ja/cache.html#retrieve-store
     */
    private function dummyHash(): string
    {
        $make = fn (): string => Hash::make(Str::random(40));

        try {
            return Cache::rememberForever(self::dummyHashCacheKey(), $make);
        } catch (Throwable $e) {
            report($e);

            return $make();
        }
    }
}
