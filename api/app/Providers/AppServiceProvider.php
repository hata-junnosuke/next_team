<?php

namespace App\Providers;

use App\Models\Application;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\PersonalAccessToken;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerInvitation;
use App\Observers\DashboardCacheObserver;
use App\Support\CurrentCompany;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope は開発用（composer の require-dev）なので、ローカル環境で、かつパッケージが入っているときだけ登録する。
        // 本番は composer install --no-dev でクラスが無いので class_exists で守る。自動登録は composer.json の
        // extra.laravel.dont-discover で止めてある（Rails で言えば Gemfile の group :development の gem を development だけで読む形）。
        // https://readouble.com/laravel/13.x/ja/telescope.html#local-only-installation
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ポリモーフィックの *_type 列（notification_logs の recipient / subject、notifications の notifiable）に
        // クラス名ではなく短い名前を入れる。クラス名を DB に書くと、名前空間を変えたときに古い行が読めなくなる。
        // enforce なので、ここにないモデルをポリモーフィックに使うと例外になる（入れ忘れに気づける）。
        // https://readouble.com/laravel/13.x/ja/eloquent-relationships.html#custom-polymorphic-types
        Relation::enforceMorphMap([
            'worker' => Worker::class,
            'user' => User::class,
            'application' => Application::class,
            'report_export' => ReportExport::class,
            'worker_invitation' => WorkerInvitation::class,
        ]);

        // Markdown のメールに差し込む値（ワーカー名・拠点名など、利用者が入力した文字列）の Markdown 記号もエスケープする。
        // 既定では HTML だけがエスケープされ、名前に [x](https://evil.example) や | を入れるとリンクや表の列になってしまう。
        // https://readouble.com/laravel/13.x/ja/mail.html#markdown-mailables
        Markdown::withSecuredEncoding();

        // メール送信の流量（メールを送るジョブ（確定通知・中止通知・前日リマインド）の RateLimited('mails') が使う）。
        // 上限はメールサーバーの都合なので企業ごとに分けず全社共通（by() を付けない）。超えたジョブは送らずにキューへ戻る。
        // https://readouble.com/laravel/13.x/ja/queues.html#rate-limiting
        RateLimiter::for('mails', fn () => Limit::perMinute((int) config('myteam.mails_per_minute')));

        // ワーカーの API トークンのモデル。持ち主（Worker）を読むときだけ企業のスコープを外す（PersonalAccessToken::tokenable()。docs/01 の 5.3）
        // https://readouble.com/laravel/13.x/ja/sanctum.html#overriding-default-models
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // ワーカーの初回登録とログインの試行回数（docs/01 の 5.3。1 分 5 回、超えたら 429）。Rails の rack-attack の throttle に当たる。
        // キーは入力の値の表記ゆれ（大文字・小文字）で数え直されないようにそろえる。企業コードは大文字、メールは小文字（Worker::normalizeEmail()。照合と同じ関数にする）
        // https://readouble.com/laravel/13.x/ja/routing.html#rate-limiting
        // 初回登録の 429 は既定（auth.throttle の「ログインの試行回数が…」）ではなく初回登録の文言にする（ワーカーアプリは message をそのまま出す）
        RateLimiter::for('worker-register', fn (Request $request) => Limit::perMinute(5)
            ->by(Worker::normalizeEmail(self::stringInput($request, 'email')).'|'.$request->ip())
            ->response(fn (Request $request, array $headers) => response()->json([
                'message' => '初回登録の試行回数が多すぎます。'.(int) ($headers['Retry-After'] ?? 60).' 秒後にお試しください。',
                'errors' => (object) [],
            ], 429, $headers)));
        RateLimiter::for('worker-login', fn (Request $request) => Limit::perMinute(5)->by(
            mb_strtoupper(self::stringInput($request, 'company_code')).'|'
            .Worker::normalizeEmail(self::stringInput($request, 'email')).'|'.$request->ip()
        ));

        // 招待の送り直し（F-27。POST /api/workers/{worker}/invitation）の回数。同じワーカーに 1 分 1 回、超えたら 429。
        // 管理者の連打や誤操作でワーカーにメールが何通も届かないようにする（docs/01 の 6.3）。
        // キーは企業とワーカー（company_id:worker_id）。別のワーカーなら続けて送れる。
        // 優先順により、いつも company の後・ルートモデルバインディングの前に動く（bootstrap/app.php）ので、{worker} は URL の文字列。
        // ルートの whereNumber('worker') で数字だけにし、(int) で 007 と 7 を同じ枠にそろえる。
        // 429 の本文は共通（bootstrap/app.php の ThrottleRequestsException。ログインの文言）ではなく送り直し用にする。
        // Retry-After などのヘッダは渡されたものをそのまま付ける
        // https://readouble.com/laravel/13.x/ja/routing.html#defining-rate-limiters
        RateLimiter::for('worker-invitation', fn (Request $request) => Limit::perMinute(1)
            ->by(CurrentCompany::id().':'.(int) $request->route('worker'))
            ->response(fn (Request $request, array $headers) => response()->json([
                'message' => '招待は同じワーカーに 1 分に 1 回まで送れます。しばらくしてからお試しください。',
                'errors' => (object) [],
            ], 429, $headers)));

        // ダッシュボードのキャッシュの破棄（F-19）。応募・募集・拠点が保存・削除されたらコミット後に消す。
        // 拠点は location_name を表示しているため（名前を変えても最長 5 分古いままにしない。リードの決定）。
        // モデルに #[ObservedBy] を付けず、ここで登録する（どのモデルに付いているかをこの 1 箇所で見渡せるようにするため。
        // モデルは別担当の所有でもある）。https://readouble.com/laravel/13.x/ja/eloquent.html#observers
        Application::observe(DashboardCacheObserver::class);
        JobPosting::observe(DashboardCacheObserver::class);
        Location::observe(DashboardCacheObserver::class);
    }

    /**
     * レート制限のキーに使う入力の値。検証（FormRequest）より前に動くので、配列などの文字列でない値は空として扱う
     */
    private static function stringInput(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? trim($value) : '';
    }
}
