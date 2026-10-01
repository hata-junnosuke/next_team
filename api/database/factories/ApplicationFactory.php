<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は「応募中」。募集とワーカーは同じ企業に作られる。
 *
 * 状態のメソッドはテストの前提を作るためのもので、status を直接入れる（ApplicationService を通さない）。
 * アプリのコードで状態を変えるときは必ず Application::transitionTo() を通す。
 * confirmed() は確定時にサービスがする「募集の時給・交通費のコピー」と confirmed_at も埋めるので、
 * 担当 C（勤怠）は担当 B（承認）の実装を待たずに確定済みの応募を作れる。
 *
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_posting_id' => JobPosting::factory(),
            'worker_id' => Worker::factory(),
            'status' => ApplicationStatus::Applied,
        ];
    }

    /**
     * 確定（F-11）。confirmed_at と、募集の時給・交通費のコピー（confirmed_hourly_wage / confirmed_transport_allowance）を埋める
     */
    public function confirmed(): static
    {
        return $this->state([
            'status' => ApplicationStatus::Confirmed,
            'confirmed_at' => now(),
            ...self::wageSnapshot(),
        ]);
    }

    /**
     * 却下（F-12）
     */
    public function rejected(): static
    {
        return $this->state(['status' => ApplicationStatus::Rejected]);
    }

    /**
     * キャンセル（F-13）。確定の後にキャンセルされた形（確定時の値と理由区分・日時が入る）。
     * 募集の中止による自動キャンセルは cancelled(CancelReason::Company)
     */
    public function cancelled(CancelReason $reason = CancelReason::Worker): static
    {
        return $this->state([
            'status' => ApplicationStatus::Cancelled,
            'confirmed_at' => now()->subDay(),
            ...self::wageSnapshot(),
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * 確定時にサービスがコピーする値と同じもの。
     * 列ごとのクロージャにしているのは、state() に渡したクロージャの時点では job_posting_id がまだファクトリのままで、
     * 列のクロージャなら作成済みの募集の ID を受け取れるため（Factory::expandAttributes の順）
     *
     * @return array<string, \Closure(array<string, mixed>): int>
     */
    private static function wageSnapshot(): array
    {
        return [
            'confirmed_hourly_wage' => fn (array $attributes) => JobPosting::query()->findOrFail($attributes['job_posting_id'])->hourly_wage,
            'confirmed_transport_allowance' => fn (array $attributes) => JobPosting::query()->findOrFail($attributes['job_posting_id'])->transport_allowance,
        ];
    }
}
