<?php

namespace Tests\Feature;

use App\Exceptions\DomainException;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * bootstrap/app.php の例外 → JSON の変換（docs/01 4.4・6.1）。
 * 検証用のルートはテストの中だけで登録する。
 */
class ErrorResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'company'])->prefix('api')->group(function () {
            Route::get('/_test/domain', fn () => throw new DomainException('定員に達しているため承認できません。'));
            Route::get('/_test/domain-field', fn () => throw new DomainException('すでに応募しています。', field: 'worker_id'));
            Route::get('/_test/company', fn () => ['company_id' => CurrentCompany::id()]);
        });
    }

    public function test_業務ルール違反は422でmessageと空のerrorsを返す(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/_test/domain')
            ->assertStatus(422)
            ->assertExactJson([
                'message' => '定員に達しているため承認できません。',
                'errors' => [],
            ]);
    }

    public function test_項目つきの業務ルール違反はerrorsにその項目が入る(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/_test/domain-field')
            ->assertStatus(422)
            ->assertJsonPath('message', 'すでに応募しています。')
            ->assertJsonPath('errors.worker_id.0', 'すでに応募しています。');
    }

    public function test_存在しないapiは404で日本語のメッセージ(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);
    }

    public function test_acceptなしの未定義パスでも404のjsonでerrorsは空オブジェクト(): void
    {
        $response = $this->get('/does-not-exist');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);

        // 空配列 [] ではなく空オブジェクト {} で返す（docs/01 6.1。web の zod は record として読む）
        $this->assertStringContainsString('"errors":{}', $response->getContent());
    }

    public function test_companyミドルウェアがログイン中の管理者の企業を設定する(): void
    {
        // setUp で入れた値ではなく、ミドルウェアが入れた値であることを確かめるため、わざと別の値にしておく
        CurrentCompany::set($this->company->id + 1000);

        $this->actingAsAdmin()
            ->getJson('/api/_test/company')
            ->assertOk()
            ->assertExactJson(['company_id' => $this->company->id]);
    }

    public function test_業務ルール違反のerrorsは配列ではなく空のjsonオブジェクト(): void
    {
        // web の zod は errors を Record<string, string[]> として parse する。[] だと型がずれる
        $content = $this->actingAsAdmin()
            ->getJson('/api/_test/domain')
            ->assertStatus(422)
            ->getContent();

        $this->assertStringContainsString('"errors":{}', $content);
    }

    public function test_業務ルール違反でも未ログインなら先に401(): void
    {
        $this->getJson('/api/_test/domain')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_アプリのタイムゾーンはasia_tokyo(): void
    {
        $this->assertSame('Asia/Tokyo', config('app.timezone'));
        $this->assertSame('Asia/Tokyo', now()->timezoneName);
    }
}
