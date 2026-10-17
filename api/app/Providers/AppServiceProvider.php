<?php

namespace App\Providers;

use App\Models\Application;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\Worker;
use App\Observers\DashboardCacheObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
            'job_posting' => JobPosting::class,
            'report_export' => ReportExport::class,
        ]);

        // Markdown のメールに差し込む値（ワーカー名・拠点名など、利用者が入力した文字列）の Markdown 記号もエスケープする。
        // 既定では HTML だけがエスケープされ、名前に [x](https://evil.example) や | を入れるとリンクや表の列になってしまう。
        // https://readouble.com/laravel/13.x/ja/mail.html#markdown-mailables
        Markdown::withSecuredEncoding();

        // メール送信の流量（メールを送るジョブ（確定通知・中止通知・前日リマインド）の RateLimited('mails') が使う）。
        // 上限はメールサーバーの都合なので企業ごとに分けず全社共通（by() を付けない）。超えたジョブは送らずにキューへ戻る。
        // https://readouble.com/laravel/13.x/ja/queues.html#rate-limiting
        RateLimiter::for('mails', fn () => Limit::perMinute((int) config('myteam.mails_per_minute')));

        // ダッシュボードのキャッシュの破棄（F-19）。応募・募集・拠点が保存・削除されたらコミット後に消す。
        // 拠点は location_name を表示しているため（名前を変えても最長 5 分古いままにしない。リードの決定）。
        // モデルに #[ObservedBy] を付けず、ここで登録する（どのモデルに付いているかをこの 1 箇所で見渡せるようにするため。
        // モデルは別担当の所有でもある）。https://readouble.com/laravel/13.x/ja/eloquent.html#observers
        Application::observe(DashboardCacheObserver::class);
        JobPosting::observe(DashboardCacheObserver::class);
        Location::observe(DashboardCacheObserver::class);
    }
}
