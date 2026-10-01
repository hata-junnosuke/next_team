<?php

namespace Tests;

use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Feature テストの土台。
 * RefreshDatabase（Rails の transactional tests に相当）で毎回 DB を作り直し、
 * 自社（$this->company）と管理者（$this->admin）を用意して CurrentCompany に設定する。
 * https://readouble.com/laravel/13.x/ja/database-testing.html
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->for($this->company)->create();

        CurrentCompany::set($this->company->id);
    }

    /**
     * 管理者としてログインした状態にする。auth:sanctum は web ガードのログインを受け付ける。
     */
    protected function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin);
    }
}
