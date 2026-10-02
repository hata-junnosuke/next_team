<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

/**
 * Telescope（開発用のデバッグ画面。リクエスト・クエリ・ジョブ・メールを記録する）の設定と入口の判定。
 * 開発用なので bootstrap/providers.php には書かず、AppServiceProvider::register() がローカル環境だけで登録する。
 */
class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /** Telescope が隠した値の代わりに入れる文字列（RequestWatcher::hideParameters() と同じ） */
    private const MASK = '********';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        // ローカルでしか登録しないので Telescope::filter() は書かず全件記録する
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        // ローカルでも Cookie と CSRF トークンは記録しない（security.md: ログにパスワードや Cookie を入れない）。
        // パスワード（password / password_confirmation）と authorization ヘッダは Telescope が既定で隠す。
        // password_hash_web / password_hash_sanctum は Sanctum の AuthenticateSession がセッションに入れるパスワードのハッシュ。
        // Telescope はセッションの中身も同じ一覧で隠すので、ここに足す。
        Telescope::hideRequestParameters([
            '_token',
            'password_hash_web',
            'password_hash_sanctum',
            // F-23 のダウンロード（GET /report-exports/{id}/download?expires=...&signature=...）のクエリの署名
            'signature',
        ]);

        // RequestWatcher は応答ヘッダも同じ一覧で隠す。set-cookie を入れないと laravel_session と XSRF-TOKEN の値が残る
        Telescope::hideRequestHeaders([
            'cookie',
            'set-cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);

        // F-23 の出力の応答に入る署名付きダウンロード URL（ReportExportResource の download_url。30 分有効で、
        // URL を持っていること自体が認可になる）。詳細（{data: {...}}）はドット記法のキーで隠せる
        Telescope::hideResponseParameters([
            'data.download_url',
        ]);

        // 一覧（{data: [{...}, ...]}）は hideResponseParameters では隠せない。RequestWatcher::hideParameters() は
        // Arr::get() / Arr::set() で、どちらもワイルドカード（data.*.download_url）を解釈しない（'*' という名前のキーを探す）。
        // URI（fullUrl()）のクエリの署名もどの一覧の対象でもない。そこで記録の直後、保存（リクエストの終了時）の前に
        // エントリの内容を書き換える。afterRecording のコールバックは 1 つしか持てないので、ほかで使うときはここにまとめる
        Telescope::afterRecording(function (Telescope $telescope, IncomingEntry $entry): void {
            if ($entry->type === EntryType::REQUEST) {
                $entry->content = $this->hideSignedUrls($entry->content);
            }
        });
    }

    /**
     * リクエストの記録から署名付き URL を消す。一覧の各要素の download_url と、URI とセッションの直前の URL（_previous.url）のクエリの signature
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function hideSignedUrls(array $content): array
    {
        if (is_array($content['response']['data'] ?? null) && array_is_list($content['response']['data'])) {
            foreach ($content['response']['data'] as $i => $item) {
                // hideParameters() と同じく、値があるときだけ隠す（未完了の null は null のまま）
                if (is_array($item) && ! empty($item['download_url'])) {
                    $content['response']['data'][$i]['download_url'] = self::MASK;
                }
            }
        }

        if (is_string($content['uri'] ?? null)) {
            $content['uri'] = $this->maskSignature($content['uri']);
        }

        // ダウンロードのルートは statefulApi の中なので、ブラウザから開くと StartSession が storeCurrentUrl() で
        // fullUrl()（署名入り）を _previous.url に入れ、RequestWatcher の session 欄に残る。URI と同じく伏せる
        if (is_string($content['session']['_previous']['url'] ?? null)) {
            $content['session']['_previous']['url'] = $this->maskSignature($content['session']['_previous']['url']);
        }

        return $content;
    }

    /**
     * URL のクエリの signature の値を伏せる。preg_replace() が失敗（null）したときは URL ごと伏せる（署名を残さない側に倒す）
     */
    private function maskSignature(string $url): string
    {
        return preg_replace('/([?&]signature=)[^&#]*/', '$1'.self::MASK, $url) ?? self::MASK;
    }

    /**
     * 親の authorization() は「ローカル環境 または Gate」で、ローカルなら未ログインでも通してしまう。
     * security.md（ローカル環境 かつ ログイン済み管理者だけ）に合わせ、「ログイン済み かつ Gate」に上書きする。
     * https://readouble.com/laravel/13.x/ja/telescope.html#dashboard-authorization
     */
    protected function authorization(): void
    {
        $this->gate();

        Telescope::auth(function ($request): bool {
            $user = $request->user();

            return $user !== null && Gate::forUser($user)->allows('viewTelescope');
        });
    }

    /**
     * ローカル環境のログイン済み管理者（User）だけ。環境はリクエストのたびに見る。
     */
    protected function gate(): void
    {
        // ローカル専用の開発ツールなので所属企業を問わない（テナント分離の例外）
        Gate::define('viewTelescope', fn (?User $user = null): bool => $user !== null && app()->environment('local'));
    }
}
