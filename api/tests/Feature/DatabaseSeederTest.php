<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\JobPostingStatus;
use App\Models\Application;
use App\Models\Attendance;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\User;
use App\Models\Worker;
use App\Support\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

/**
 * 開発用シーダー（make fresh）。管理者でログインでき、他社の拠点が自社の一覧に出ないこと。
 */
class DatabaseSeederTest extends TestCase
{
    public function test_シーダーの管理者でログインすると自社の拠点だけが見える(): void
    {
        $this->seed(DatabaseSeeder::class);
        Context::forget('company_id');

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $other = User::query()->where('email', 'other@example.com')->firstOrFail();
        $this->assertNotSame($admin->company_id, $other->company_id);

        $response = $this->actingAs($admin)->getJson('/api/locations')->assertOk();

        $expected = CurrentCompany::runAs($admin->company_id, fn () => Location::query()->count());
        $this->assertGreaterThan(0, $expected);
        $response->assertJsonCount($expected, 'data');
        $this->assertNotContains('他社の拠点（自社の画面に出てはいけない）', array_column($response->json('data'), 'name'));
    }

    public function test_シーダーの管理者はpasswordでログインできる(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->postJson('/login', ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertSuccessful();
        $this->assertAuthenticated();
    }

    public function test_シーダーに動線を手で確かめられる募集と応募と勤怠が入る(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $other = User::query()->where('email', 'other@example.com')->firstOrFail();

        CurrentCompany::runAs($admin->company_id, function () {
            $this->assertTrue(Worker::query()->where('is_active', false)->exists());
            $this->assertGreaterThanOrEqual(5, Worker::query()->where('is_active', true)->count());

            $postingStatuses = JobPosting::query()->pluck('status')->unique();
            foreach (JobPostingStatus::cases() as $status) {
                $this->assertContains($status, $postingStatuses, "募集の状態 {$status->value} がない");
            }

            $applicationStatuses = Application::query()->pluck('status')->unique();
            foreach (ApplicationStatus::cases() as $status) {
                $this->assertContains($status, $applicationStatuses, "応募の状態 {$status->value} がない");
            }

            // 勤怠は確定済みの応募にだけ付いている
            $this->assertGreaterThan(0, Attendance::query()->count());
            $this->assertSame(0, Attendance::query()->whereHas(
                'application', fn ($q) => $q->where('status', '!=', ApplicationStatus::Confirmed),
            )->count());
            // 確定済みの応募には時給と交通費がコピーされている
            $this->assertSame(0, Application::query()->where('status', ApplicationStatus::Confirmed)->whereNull('confirmed_hourly_wage')->count());
        });

        CurrentCompany::runAs($other->company_id, function () {
            $this->assertGreaterThan(0, JobPosting::query()->count());
            $this->assertGreaterThan(0, Attendance::query()->count());
        });
    }
}
