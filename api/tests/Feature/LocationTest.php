<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * F-01 拠点の登録・一覧・編集・削除（docs/01 6.2）。
 * P1 では募集（job_postings）がまだないので job_postings_count は 0、deletable は true（P2-02 で実値にする）。
 */
class LocationTest extends TestCase
{
    public function test_拠点を登録すると201で一覧に出る(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/locations', ['name' => '渋谷店', 'address' => '東京都渋谷区1-1-1'])
            ->assertCreated()
            ->assertJsonPath('data.name', '渋谷店')
            ->assertJsonPath('data.address', '東京都渋谷区1-1-1')
            ->assertJsonPath('data.job_postings_count', 0)
            ->assertJsonPath('data.deletable', true);

        $this->assertDatabaseHas('locations', ['name' => '渋谷店', 'company_id' => $this->company->id]);

        $this->getJson('/api/locations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'address', 'job_postings_count', 'deletable']]])
            ->assertJsonPath('data.0.name', '渋谷店');
    }

    public function test_住所は省略できる(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/locations', ['name' => '新宿店'])
            ->assertCreated()
            ->assertJsonPath('data.address', null);
    }

    public function test_名称が空だと422でerrors_nameが日本語(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/locations', ['name' => '', 'address' => '東京都'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => '名称は必須項目です。']);

        $this->assertDatabaseCount('locations', 0);
    }

    public function test_拠点の詳細を取得できる(): void
    {
        $location = Location::factory()->create(['name' => '池袋店']);

        $this->actingAsAdmin()
            ->getJson("/api/locations/{$location->id}")
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $location->id,
                'name' => '池袋店',
                'address' => $location->address,
                'job_postings_count' => 0,
                'deletable' => true,
            ]]);
    }

    public function test_拠点を更新できる(): void
    {
        $location = Location::factory()->create(['name' => '旧名称']);

        $this->actingAsAdmin()
            ->putJson("/api/locations/{$location->id}", ['name' => '新名称', 'address' => '大阪府'])
            ->assertOk()
            ->assertJsonPath('data.name', '新名称')
            ->assertJsonPath('data.address', '大阪府');

        $this->assertDatabaseHas('locations', ['id' => $location->id, 'name' => '新名称', 'address' => '大阪府']);
    }

    public function test_更新でも名称が空だと422(): void
    {
        $location = Location::factory()->create(['name' => '旧名称']);

        $this->actingAsAdmin()
            ->putJson("/api/locations/{$location->id}", ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseHas('locations', ['id' => $location->id, 'name' => '旧名称']);
    }

    public function test_使われていない拠点は削除できて204(): void
    {
        $location = Location::factory()->create();

        $this->actingAsAdmin()
            ->deleteJson("/api/locations/{$location->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    public function test_他社の拠点は404(): void
    {
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Location::factory()->create());

        // 本番のリクエストは CurrentCompany が空の状態で始まる。setUp で入れた値に助けられないよう消してから叩く
        Context::forget('company_id');

        $this->actingAsAdmin()->getJson("/api/locations/{$theirs->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);
        $this->putJson("/api/locations/{$theirs->id}", ['name' => '乗っ取り'])->assertNotFound();
        $this->deleteJson("/api/locations/{$theirs->id}")->assertNotFound();

        $this->getJson('/api/locations')->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('locations', ['id' => $theirs->id, 'name' => $theirs->name]);
    }

    public function test_一覧はpageとper_pageでページングされmetaが付く(): void
    {
        Location::factory()->count(5)->create();

        $this->actingAsAdmin()
            ->getJson('/api/locations?page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
    }

    public function test_per_pageは最大100に丸められる(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/locations?per_page=1000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_locationsとusersのcompany_idにindexがある(): void
    {
        // SQLite は外部キーに索引を自動で張らない。企業で絞るクエリが全件走査にならないよう明示する（docs/01 3.1）
        foreach (['locations', 'users'] as $table) {
            $indexed = collect(Schema::getIndexes($table))
                ->contains(fn (array $index) => $index['columns'] === ['company_id']);

            $this->assertTrue($indexed, "{$table}.company_id に INDEX がない");
        }
    }

    public function test_未ログインだと401(): void
    {
        Context::forget('company_id');

        $this->getJson('/api/locations')->assertUnauthorized();
    }

    public function test_422の本文はmessageとerrorsの形で日本語(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/locations', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['name']])
            ->assertJsonPath('errors.name.0', '名称は必須項目です。')
            ->assertJsonMissingPath('errors.address');
    }

    public function test_名称が256文字だと422で255文字なら登録できる(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/locations', ['name' => str_repeat('あ', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => '名称の文字数は、255文字以下である必要があります。']);

        $this->postJson('/api/locations', ['name' => str_repeat('あ', 255)])->assertCreated();
    }

    public function test_住所が256文字だと422でerrors_addressが日本語(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/locations', ['name' => '渋谷店', 'address' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address' => '住所の文字数は、255文字以下である必要があります。']);

        $this->assertDatabaseCount('locations', 0);
    }

    public function test_名称が文字列でないと422(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/locations', ['name' => ['渋谷店']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => '名称には、文字列を指定してください。']);
    }

    public function test_登録の201レスポンスはlocation_resourceの形そのもの(): void
    {
        $response = $this->actingAsAdmin()
            ->postJson('/api/locations', ['name' => '渋谷店', 'address' => '東京都'])
            ->assertCreated();

        $id = Location::query()->value('id');
        $response->assertExactJson(['data' => [
            'id' => $id,
            'name' => '渋谷店',
            'address' => '東京都',
            'job_postings_count' => 0,
            'deletable' => true,
        ]]);
    }

    public function test_登録時に他社のcompany_idを送っても自社の拠点になる(): void
    {
        $other = Company::factory()->create();
        // 本番と同じく、company ミドルウェアがログイン中の管理者から企業を決めることを確かめる
        Context::forget('company_id');

        $this->actingAsAdmin()
            ->postJson('/api/locations', ['name' => '渋谷店', 'company_id' => $other->id])
            ->assertCreated()
            ->assertJsonMissingPath('data.company_id');

        $this->assertDatabaseHas('locations', ['name' => '渋谷店', 'company_id' => $this->company->id]);
        $this->assertDatabaseMissing('locations', ['company_id' => $other->id]);
    }

    public function test_更新時にcompany_idを送っても他社へ付け替えられない(): void
    {
        $other = Company::factory()->create();
        $location = Location::factory()->create(['name' => '旧名称']);
        Context::forget('company_id');

        $this->actingAsAdmin()
            ->putJson("/api/locations/{$location->id}", ['name' => '新名称', 'company_id' => $other->id])
            ->assertOk();

        $this->assertDatabaseHas('locations', ['id' => $location->id, 'name' => '新名称', 'company_id' => $this->company->id]);
    }

    public function test_一覧は他社の拠点を含まずmeta_totalも自社分だけ(): void
    {
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, fn () => Location::factory()->count(3)->create());
        $mine = Location::factory()->count(2)->create();
        Context::forget('company_id');

        $response = $this->actingAsAdmin()
            ->getJson('/api/locations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->assertEqualsCanonicalizing($mine->pluck('id')->all(), array_column($response->json('data'), 'id'));
    }

    public function test_一覧の要素はlocation_resourceの形(): void
    {
        $location = Location::factory()->create(['name' => '池袋店', 'address' => null]);

        $this->actingAsAdmin()
            ->getJson('/api/locations')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'address', 'job_postings_count', 'deletable']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links',
            ])
            ->assertJsonPath('data.0', [
                'id' => $location->id,
                'name' => '池袋店',
                'address' => null,
                'job_postings_count' => 0,
                'deletable' => true,
            ]);
    }

    public function test_per_pageを省略すると20件(): void
    {
        Location::factory()->count(21)->create();

        $this->actingAsAdmin()
            ->getJson('/api/locations')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_per_pageは100ちょうどならそのまま(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/locations?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_per_pageが101だと100に丸められる(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/locations?per_page=101')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_per_pageが0や負や数字でないと1以上に丸められ500にならない(): void
    {
        Location::factory()->count(2)->create();
        $this->actingAsAdmin();

        foreach (['0', '-5', 'abc'] as $value) {
            $this->getJson("/api/locations?per_page={$value}")
                ->assertOk()
                ->assertJsonPath('meta.per_page', 1)
                ->assertJsonCount(1, 'data');
        }
    }

    public function test_最終ページを超えるpageは空のdataで200(): void
    {
        Location::factory()->count(2)->create();

        $this->actingAsAdmin()
            ->getJson('/api/locations?page=5&per_page=2')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_一覧はid順に並ぶ(): void
    {
        $ids = Location::factory()->count(3)->create()->pluck('id')->all();

        $response = $this->actingAsAdmin()->getJson('/api/locations')->assertOk();

        $this->assertSame($ids, array_column($response->json('data'), 'id'));
    }

    public function test_存在しない拠点は404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/locations/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);
        $this->putJson('/api/locations/999999', ['name' => 'x'])->assertNotFound();
        $this->deleteJson('/api/locations/999999')->assertNotFound();
    }

    public function test_他社の拠点の更新は検証より先に404になる(): void
    {
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Location::factory()->create());
        Context::forget('company_id');

        // 入力が不正でも 422 で「存在する」ことを漏らさない
        $this->actingAsAdmin()
            ->putJson("/api/locations/{$theirs->id}", ['name' => ''])
            ->assertNotFound();
    }

    public function test_未ログインだと全操作が401で書き込まれない(): void
    {
        $location = Location::factory()->create(['name' => '旧名称']);
        Context::forget('company_id');

        $this->getJson("/api/locations/{$location->id}")->assertUnauthorized();
        $this->postJson('/api/locations', ['name' => '渋谷店'])->assertUnauthorized();
        $this->putJson("/api/locations/{$location->id}", ['name' => '新名称'])->assertUnauthorized();
        $this->deleteJson("/api/locations/{$location->id}")->assertUnauthorized();

        $this->assertDatabaseCount('locations', 1);
        $this->assertDatabaseHas('locations', ['id' => $location->id, 'name' => '旧名称']);
    }
}
