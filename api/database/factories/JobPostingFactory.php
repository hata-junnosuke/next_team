<?php

namespace Database\Factories;

use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は「1 週間後の 9:00-17:00、定員 3、時給 1,300 円、交通費 500 円、募集中」。
 * 休憩 60 分なら 420 分 → 9,100 + 500 = 9,600 円（業務ルールの例と同じ数字）。
 *
 * 状態のメソッド（closed() / cancelled()）はテストの前提を作るためのもので、status を直接入れる。
 * アプリのコードで状態を変えるときは必ず JobPosting::transitionTo() を通す。
 * 拠点は同じ企業に作られる（Location::factory() も CurrentCompany の企業で作られる）。
 *
 * @extends Factory<JobPosting>
 */
class JobPostingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'job_title' => fake()->randomElement(['ピッキング', '品出し', '検品', '梱包', 'レジ']),
            'work_date' => now()->addWeek()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 3,
            'hourly_wage' => 1300,
            'transport_allowance' => 500,
            'status' => JobPostingStatus::Open,
        ];
    }

    /**
     * 勤務日を指定する（例: ->onDate('2026-09-30')）
     */
    public function onDate(string $date): static
    {
        return $this->state(['work_date' => $date]);
    }

    /**
     * 締切（F-07。新規応募の受付停止）
     */
    public function closed(): static
    {
        return $this->state(['status' => JobPostingStatus::Closed]);
    }

    /**
     * 中止（F-07。編集・応募不可）。応募の自動遷移は行わない（必要ならテスト側で応募の状態も作る）
     */
    public function cancelled(): static
    {
        return $this->state(['status' => JobPostingStatus::Cancelled]);
    }
}
