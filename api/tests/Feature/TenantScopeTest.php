<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\TenantRecord;
use Tests\TestCase;

/**
 * テナント分離（docs/01 4.2、要件「企業IDで分ける」）。
 * BelongsToCompany（Rails の default_scope + before_create に相当）と SetCurrentCompany（別名 company）を検証する。
 * https://readouble.com/laravel/13.x/ja/eloquent.html#global-scopes
 */
class TenantScopeTest extends TestCase
{
    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name');
            $table->timestamps();
        });

        $this->other = Company::factory()->create();

        Route::middleware(['api', 'auth:sanctum', 'company'])->prefix('api')->group(function () {
            Route::get('/_test/records/{record}', fn (TenantRecord $record) => ['data' => ['id' => $record->id]]);
        });
        // company だけを付けたルート。auth:sanctum を付け忘れても企業なしで先へ進まないことを確かめる
        Route::middleware(['api', 'company'])->prefix('api')->group(function () {
            Route::get('/_test/company-only', fn () => ['company_id' => CurrentCompany::id()]);
        });
    }

    /**
     * 本番のリクエストは CurrentCompany が空の状態で始まる。setUp で入れた値に助けられないよう消してから叩く
     */
    private function forgetCompanyLikeANewRequest(): void
    {
        Context::forget('company_id');
    }

    private function insertFor(Company $company, string $name): int
    {
        // スコープを通さずに他社の行を作るため、クエリビルダで直接入れる
        return DB::table('tenant_records')->insertGetId([
            'company_id' => $company->id,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_グローバルスコープで自社の行だけが見える(): void
    {
        $mine = $this->insertFor($this->company, '自社');
        $this->insertFor($this->other, '他社');

        $this->assertSame([$mine], TenantRecord::pluck('id')->all());
        $this->assertSame(1, TenantRecord::count());
    }

    public function test_作成時のcompany_idはcurrent_companyで埋まり渡した値は無視される(): void
    {
        $record = TenantRecord::create(['name' => '新規', 'company_id' => $this->other->id]);

        $this->assertSame($this->company->id, $record->company_id);
        $this->assertDatabaseHas('tenant_records', ['id' => $record->id, 'company_id' => $this->company->id]);
    }

    public function test_企業が未設定のまま検索すると全件を返さずlogic_exception(): void
    {
        $this->insertFor($this->company, '自社');
        Context::forget('company_id');

        $this->expectException(LogicException::class);

        TenantRecord::count();
    }

    public function test_他社の行のidをurlに入れると404(): void
    {
        $theirs = $this->insertFor($this->other, '他社');
        $this->forgetCompanyLikeANewRequest();

        $this->actingAsAdmin()
            ->getJson("/api/_test/records/{$theirs}")
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);
    }

    public function test_自社の行のidなら取得できる(): void
    {
        $mine = $this->insertFor($this->company, '自社');
        $this->forgetCompanyLikeANewRequest();

        $this->actingAsAdmin()
            ->getJson("/api/_test/records/{$mine}")
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $mine]]);
    }

    public function test_他社の管理者でログインすると他社の行だけが見える(): void
    {
        $mine = $this->insertFor($this->company, '自社');
        $otherAdmin = User::factory()->for($this->other)->create();
        $this->forgetCompanyLikeANewRequest();

        $this->actingAs($otherAdmin)
            ->getJson("/api/_test/records/{$mine}")
            ->assertNotFound();
    }

    public function test_company_ミドルウェアは未ログインなら401で先へ進まない(): void
    {
        Context::forget('company_id');

        $this->getJson('/api/_test/company-only')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertFalse(CurrentCompany::check());
    }
}
