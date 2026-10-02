<?php

namespace App\Providers;

use App\Models\Application;
use App\Models\JobPosting;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}
