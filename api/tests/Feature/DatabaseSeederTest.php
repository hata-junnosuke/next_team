<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

/**
 * 開発用シーダー（P1-02。make fresh）。管理者でログインでき、他社の拠点が自社の一覧に出ないこと。
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
}
