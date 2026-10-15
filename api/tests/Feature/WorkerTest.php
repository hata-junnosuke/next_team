<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Company;
use App\Models\Worker;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F-02〜F-04 ワーカーの登録・編集・有効/無効・一覧と検索（docs/01 6.3）。
 * WorkerResource: {id, name, email, phone, is_active}
 */
class WorkerTest extends TestCase
{
    public function test_ワーカーを登録できる(): void
    {
        $response = $this->actingAsAdmin()
            ->postJson('/api/workers', ['name' => '山田太郎', 'email' => 'yamada@example.com', 'phone' => '090-1234-5678'])
            ->assertCreated();

        $id = Worker::query()->value('id');
        $response->assertExactJson(['data' => [
            'id' => $id,
            'name' => '山田太郎',
            'email' => 'yamada@example.com',
            'phone' => '090-1234-5678',
            'is_active' => true,
        ]]);

        $this->assertDatabaseHas('workers', ['id' => $id, 'company_id' => $this->company->id, 'is_active' => true]);
    }

    public function test_電話番号は省略でき空文字はnullになる(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/workers', ['name' => '山田太郎', 'email' => 'yamada@example.com', 'phone' => ''])
            ->assertCreated()
            ->assertJsonPath('data.phone', null);
    }

    public function test_メールが空だと422(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/workers', ['name' => '山田太郎', 'email' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'メールアドレスは必須項目です。']);

        $this->assertDatabaseCount('workers', 0);
    }

    public function test_メール形式でないと422(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/workers', ['name' => '山田太郎', 'email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_氏名が空だと422(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/workers', ['name' => '', 'email' => 'yamada@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => '氏名は必須項目です。']);
    }

    public function test_登録時にis_activeやcompany_idを送っても無視される(): void
    {
        $other = Company::factory()->create();
        Context::forget('company_id');

        $this->actingAsAdmin()
            ->postJson('/api/workers', [
                'name' => '山田太郎', 'email' => 'yamada@example.com', 'is_active' => false, 'company_id' => $other->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('workers', ['name' => '山田太郎', 'company_id' => $this->company->id, 'is_active' => true]);
    }

    public function test_ワーカーの詳細を取得できる(): void
    {
        $worker = Worker::factory()->create(['name' => '佐藤花子', 'phone' => null]);

        $this->actingAsAdmin()
            ->getJson("/api/workers/{$worker->id}")
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $worker->id,
                'name' => '佐藤花子',
                'email' => $worker->email,
                'phone' => null,
                'is_active' => true,
            ]]);
    }

    public function test_ワーカーを更新できる(): void
    {
        $worker = Worker::factory()->create(['name' => '旧氏名']);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '新氏名', 'email' => 'new@example.com', 'phone' => '080-0000-0000'])
            ->assertOk()
            ->assertJsonPath('data.name', '新氏名')
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.phone', '080-0000-0000');

        $this->assertDatabaseHas('workers', ['id' => $worker->id, 'name' => '新氏名', 'email' => 'new@example.com']);
    }

    public function test_更新でもメールが空だと422(): void
    {
        $worker = Worker::factory()->create(['email' => 'old@example.com']);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'email' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'メールアドレスは必須項目です。']);

        $this->assertDatabaseHas('workers', ['id' => $worker->id, 'email' => 'old@example.com']);
    }

    public function test_更新ではis_activeを変えられない(): void
    {
        $worker = Worker::factory()->create();

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'email' => 'a@example.com', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_無効に切り替えられる(): void
    {
        $worker = Worker::factory()->create();

        $this->actingAsAdmin()
            ->patchJson("/api/workers/{$worker->id}/active", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.id', $worker->id)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('workers', ['id' => $worker->id, 'is_active' => false]);

        // 有効に戻せる
        $this->patchJson("/api/workers/{$worker->id}/active", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_is_activeがないか真偽値でないと切り替えは422(): void
    {
        $worker = Worker::factory()->create();
        $this->actingAsAdmin();

        $this->patchJson("/api/workers/{$worker->id}/active", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);
        $this->patchJson("/api/workers/{$worker->id}/active", ['is_active' => 'yes'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);

        $this->assertDatabaseHas('workers', ['id' => $worker->id, 'is_active' => true]);
    }

    public function test_一覧はページングされmetaが付きid順(): void
    {
        $ids = Worker::factory()->count(5)->create()->pluck('id')->all();

        $response = $this->actingAsAdmin()
            ->getJson('/api/workers?page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'phone', 'is_active']], 'links']);

        $this->assertSame(array_slice($ids, 0, 2), array_column($response->json('data'), 'id'));
    }

    public function test_氏名の部分一致で検索できる(): void
    {
        $yamada = Worker::factory()->create(['name' => '山田太郎']);
        $koyamada = Worker::factory()->create(['name' => '小山田花子']);
        Worker::factory()->create(['name' => '佐藤次郎']);

        $response = $this->actingAsAdmin()
            ->getJson('/api/workers?q='.urlencode('山田'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->assertSame([$yamada->id, $koyamada->id], array_column($response->json('data'), 'id'));
    }

    public function test_検索語の百分率記号と下線は文字としてそのまま扱う(): void
    {
        // LIKE のワイルドカード（% と _）を入れても全件にならない（SQLite は ESCAPE の明示が必要）
        $percent = Worker::factory()->create(['name' => '100%田中']);
        Worker::factory()->create(['name' => '山田太郎']);
        $underscore = Worker::factory()->create(['name' => 'a_b']);
        Worker::factory()->create(['name' => 'axb']);

        $this->actingAsAdmin()
            ->getJson('/api/workers?q='.urlencode('%'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $percent->id);

        $this->getJson('/api/workers?q='.urlencode('_'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $underscore->id);
    }

    public function test_is_activeで絞り込める(): void
    {
        $active = Worker::factory()->create();
        $inactive = Worker::factory()->inactive()->create();

        $this->actingAsAdmin()
            ->getJson('/api/workers?is_active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->getJson('/api/workers?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactive->id)
            ->assertJsonPath('data.0.is_active', false);

        // web のフォームから来る true / false の文字列も受け付ける
        $this->getJson('/api/workers?is_active=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactive->id);

        // 未指定・空なら全件
        $this->getJson('/api/workers')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/workers?is_active=')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_氏名検索とis_activeは組み合わせられる(): void
    {
        Worker::factory()->create(['name' => '山田太郎']);
        $target = Worker::factory()->inactive()->create(['name' => '山田花子']);
        Worker::factory()->inactive()->create(['name' => '佐藤次郎']);

        $this->actingAsAdmin()
            ->getJson('/api/workers?q='.urlencode('山田').'&is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id);
    }

    public function test_一覧の絞り込みの値が不正だと422(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/workers?is_active=yes')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);

        $this->getJson('/api/workers?q='.str_repeat('a', 256))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    }

    public function test_他社のワーカーは404(): void
    {
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Worker::factory()->create(['name' => '他社太郎']));

        // 本番のリクエストは CurrentCompany が空の状態で始まる。setUp で入れた値に助けられないよう消してから叩く
        Context::forget('company_id');

        $this->actingAsAdmin()->getJson("/api/workers/{$theirs->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);
        $this->putJson("/api/workers/{$theirs->id}", ['name' => '乗っ取り', 'email' => 'x@example.com'])->assertNotFound();
        $this->patchJson("/api/workers/{$theirs->id}/active", ['is_active' => false])->assertNotFound();

        $this->getJson('/api/workers')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/workers?q='.urlencode('他社'))->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('workers', ['id' => $theirs->id, 'name' => '他社太郎', 'is_active' => true]);
    }

    public function test_未ログインだと401で書き込まれない(): void
    {
        $worker = Worker::factory()->create(['name' => '旧氏名']);
        Context::forget('company_id');

        $this->getJson('/api/workers')->assertUnauthorized();
        $this->postJson('/api/workers', ['name' => 'x', 'email' => 'x@example.com'])->assertUnauthorized();
        $this->putJson("/api/workers/{$worker->id}", ['name' => 'x', 'email' => 'x@example.com'])->assertUnauthorized();
        $this->patchJson("/api/workers/{$worker->id}/active", ['is_active' => false])->assertUnauthorized();

        $this->assertDatabaseCount('workers', 1);
        $this->assertDatabaseHas('workers', ['id' => $worker->id, 'name' => '旧氏名', 'is_active' => true]);
    }

    public function test_メールのキー自体がないかnullでも422(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/workers', ['name' => '山田太郎'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'メールアドレスは必須項目です。']);

        $this->postJson('/api/workers', ['name' => '山田太郎', 'email' => null, 'phone' => '090-1234-5678'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'メールアドレスは必須項目です。']);

        // 電話だけでは登録できない（第2弾までの「いずれか必須」ではない）
        $this->assertDatabaseCount('workers', 0);
    }

    public function test_更新でメールのキーを送らないと422で元のメールが残る(): void
    {
        $worker = Worker::factory()->create(['email' => 'old@example.com']);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'phone' => '090-0000-0000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseHas('workers', ['id' => $worker->id, 'email' => 'old@example.com']);
    }

    public function test_422の本文はmessageとerrorsの形で日本語(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/workers', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['name', 'email']])
            ->assertJsonMissingValidationErrors(['phone'])
            ->assertJsonPath('errors.name.0', '氏名は必須項目です。')
            ->assertJsonPath('errors.email.0', 'メールアドレスは必須項目です。');
    }

    public function test_更新と切り替えの応答はworker_resourceの形そのもの(): void
    {
        // 電話番号は Faker の optional で入ったり入らなかったりするので、期待値の null に固定する（P2-04 で揺れを発見）
        $worker = Worker::factory()->create(['phone' => null]);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '新氏名', 'email' => 'new@example.com'])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $worker->id, 'name' => '新氏名', 'email' => 'new@example.com', 'phone' => null, 'is_active' => true,
            ]]);

        $this->patchJson("/api/workers/{$worker->id}/active", ['is_active' => false])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $worker->id, 'name' => '新氏名', 'email' => 'new@example.com', 'phone' => null, 'is_active' => false,
            ]]);
    }

    public function test_無効にしても既存の確定と応募は変わらない(): void
    {
        $worker = Worker::factory()->create();
        $confirmed = Application::factory()->confirmed()->for($worker)->create();
        $applied = Application::factory()->for($worker)->create();

        $this->actingAsAdmin()
            ->patchJson("/api/workers/{$worker->id}/active", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(ApplicationStatus::Confirmed, $confirmed->fresh()->status);
        $this->assertNotNull($confirmed->fresh()->confirmed_at);
        $this->assertSame(ApplicationStatus::Applied, $applied->fresh()->status);
    }

    public function test_無効ワーカーも詳細の取得と編集ができ一覧の全件に出る(): void
    {
        $worker = Worker::factory()->inactive()->create();

        $this->actingAsAdmin()
            ->getJson("/api/workers/{$worker->id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // 編集しても有効には戻らない（切り替えは PATCH /active だけ）
        $this->putJson("/api/workers/{$worker->id}", ['name' => '改名', 'email' => 'x@example.com'])
            ->assertOk()
            ->assertJsonPath('data.name', '改名')
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/workers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $worker->id);
    }

    public function test_同じ値に切り替えても200で状態は変わらない(): void
    {
        $worker = Worker::factory()->create();

        $this->actingAsAdmin()
            ->patchJson("/api/workers/{$worker->id}/active", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('workers', ['id' => $worker->id, 'is_active' => true]);
    }

    public function test_氏名検索は前方一致と後方一致と完全一致も拾う(): void
    {
        $prefix = Worker::factory()->create(['name' => '山田太郎']);
        $suffix = Worker::factory()->create(['name' => '小山田']);
        $exact = Worker::factory()->create(['name' => '山田']);
        Worker::factory()->create(['name' => '山本田中']);

        $response = $this->actingAsAdmin()
            ->getJson('/api/workers?q='.urlencode('山田'))
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->assertSame([$prefix->id, $suffix->id, $exact->id], array_column($response->json('data'), 'id'));
    }

    public function test_氏名検索はメールと電話には当たらない(): void
    {
        Worker::factory()->create(['name' => '佐藤', 'email' => 'yamada@example.com', 'phone' => '090-yamada']);

        $this->actingAsAdmin()
            ->getJson('/api/workers?q=yamada')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_検索語が空白だけなら絞り込まず全件(): void
    {
        Worker::factory()->count(3)->create();

        $this->actingAsAdmin()->getJson('/api/workers?q=')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/workers?q='.urlencode('   '))->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_検索語のエスケープ文字の感嘆符も文字として扱う(): void
    {
        $bang = Worker::factory()->create(['name' => '注意!太郎']);
        Worker::factory()->create(['name' => '注意太郎']);

        $this->actingAsAdmin()
            ->getJson('/api/workers?q='.urlencode('!'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bang->id);

        $this->getJson('/api/workers?q='.urlencode('!%'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_検索語は255文字までなら200(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/workers?q='.str_repeat('a', 255))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_検索とページングを組み合わせるとtotalは絞り込み後の件数(): void
    {
        Worker::factory()->count(3)->create(['name' => '山田']);
        Worker::factory()->count(2)->create(['name' => '佐藤']);

        $this->actingAsAdmin()
            ->getJson('/api/workers?q='.urlencode('山田').'&per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_一覧のper_pageは最大100で省略時は20(): void
    {
        Worker::factory()->count(101)->create();

        $this->actingAsAdmin()->getJson('/api/workers')->assertOk()
            ->assertJsonCount(20, 'data')->assertJsonPath('meta.per_page', 20);
        $this->getJson('/api/workers?per_page=101')->assertOk()
            ->assertJsonCount(100, 'data')->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/workers?per_page=0')->assertOk()->assertJsonPath('meta.per_page', 1);
    }

    public function test_メールと氏名と電話が256文字だと422(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/workers', [
                'name' => str_repeat('a', 256),
                'email' => str_repeat('a', 244).'@example.com',
                'phone' => str_repeat('1', 256),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'phone']);

        $this->assertDatabaseCount('workers', 0);
    }

    public function test_存在しないワーカーは404(): void
    {
        $this->actingAsAdmin()->getJson('/api/workers/999999')->assertNotFound();
        $this->patchJson('/api/workers/999999/active', ['is_active' => false])->assertNotFound();
    }

    public function test_他社のワーカーの更新は検証より先に404になる(): void
    {
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => Worker::factory()->create());
        Context::forget('company_id');

        // 入力が不正でも 422 にせず 404（他社の ID の存在を検証エラーで漏らさない）
        $this->actingAsAdmin()->putJson("/api/workers/{$theirs->id}", ['name' => '', 'email' => ''])->assertNotFound();
        $this->patchJson("/api/workers/{$theirs->id}/active", [])->assertNotFound();
    }

    public function test_他社のワーカーは絞り込みの組み合わせでも一覧に出ない(): void
    {
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, function () {
            Worker::factory()->create(['name' => '山田他社']);
            Worker::factory()->inactive()->create(['name' => '山田他社無効']);
        });
        $mine = Worker::factory()->inactive()->create(['name' => '山田自社']);
        Context::forget('company_id');

        $this->actingAsAdmin()
            ->getJson('/api/workers?q='.urlencode('山田').'&is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/workers?is_active=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_ワーカー一覧のクエリ数は件数に比例しない(): void
    {
        $this->actingAsAdmin();
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/workers')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        Worker::factory()->count(2)->create();
        $few = $count();
        Worker::factory()->count(10)->create();
        $many = $count();

        $this->assertSame($few, $many);
    }
}
