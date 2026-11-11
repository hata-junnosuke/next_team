<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Company;
use App\Models\Worker;
use App\Models\WorkerInvitation;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * F-02〜F-04 ワーカーの登録・編集・有効/無効・一覧と検索（docs/01 のワーカーの API）。
 * WorkerResource: {id, name, email, phone, is_active, registered_at, invitation_expires_at, can: {invite}}
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
            'registered_at' => null,
            'invitation_expires_at' => WorkerInvitation::query()->sole()->expires_at->toIso8601String(),
            'can' => ['invite' => true],
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
                'registered_at' => null,
                'invitation_expires_at' => null,
                'can' => ['invite' => true],
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
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'phone', 'is_active', 'registered_at', 'invitation_expires_at', 'can' => ['invite']]], 'links']);

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

        // 電話だけでは登録できない（メールは必須）
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
        Queue::fake();
        $this->freezeSecond(); // datetime 列は秒までなので、期待値の日時も秒にそろえる
        // 電話番号は Faker の optional で入ったり入らなかったりするので、期待値の null に固定する
        $worker = Worker::factory()->create(['email' => 'old@example.com', 'phone' => null]);
        WorkerInvitation::factory()->create(['worker_id' => $worker->id, 'expires_at' => now()->addDays(3)]);
        // 未登録のワーカーのメールを変えると、前の招待は取り消され新しいメール宛に招待を発行し直す（docs/01 の 3.1・4.5）。
        // 応答の期限は新しい招待のもの
        $expiresAt = now()->addDays((int) config('myteam.worker_invitation_days'))->toIso8601String();

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '新氏名', 'email' => 'new@example.com'])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $worker->id, 'name' => '新氏名', 'email' => 'new@example.com', 'phone' => null, 'is_active' => true,
                'registered_at' => null, 'invitation_expires_at' => $expiresAt, 'can' => ['invite' => true],
            ]]);

        $this->assertSame(1, WorkerInvitation::query()->usable()->count());

        // 無効にすると招待はできなくなる（使える招待の期限はそのまま出す）
        $this->patchJson("/api/workers/{$worker->id}/active", ['is_active' => false])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $worker->id, 'name' => '新氏名', 'email' => 'new@example.com', 'phone' => null, 'is_active' => false,
                'registered_at' => null, 'invitation_expires_at' => $expiresAt, 'can' => ['invite' => false],
            ]]);
    }

    public function test_ワーカーの応答に登録日時と招待の期限と招待できるかが入る(): void
    {
        $this->freezeSecond();

        // 未登録・有効・使える招待あり（前の取り消し済み・期限切れの招待は期限に出さない）
        $invited = Worker::factory()->create(['phone' => null]);
        WorkerInvitation::factory()->revoked()->create(['worker_id' => $invited->id, 'expires_at' => now()->addDays(9)]);
        WorkerInvitation::factory()->expired()->create(['worker_id' => $invited->id]);
        $usable = WorkerInvitation::factory()->create(['worker_id' => $invited->id, 'expires_at' => now()->addDays(5)]);

        // 未登録・有効・使える招待なし（期限切れだけ）
        $expired = Worker::factory()->create();
        WorkerInvitation::factory()->expired()->create(['worker_id' => $expired->id]);

        // 登録済み（招待は使用済み）
        $registered = Worker::factory()->registered()->create(['registered_at' => now()->subDay()]);
        WorkerInvitation::factory()->used()->create(['worker_id' => $registered->id]);

        // 未登録・無効
        $inactive = Worker::factory()->inactive()->create();

        $this->actingAsAdmin();

        $this->getJson("/api/workers/{$invited->id}")
            ->assertOk()
            ->assertJsonPath('data.registered_at', null)
            ->assertJsonPath('data.invitation_expires_at', $usable->expires_at->toIso8601String())
            ->assertJsonPath('data.can.invite', true);

        $this->getJson("/api/workers/{$expired->id}")
            ->assertOk()
            ->assertJsonPath('data.invitation_expires_at', null)
            ->assertJsonPath('data.can.invite', true);

        $this->getJson("/api/workers/{$registered->id}")
            ->assertOk()
            ->assertJsonPath('data.registered_at', now()->subDay()->toIso8601String())
            ->assertJsonPath('data.invitation_expires_at', null)
            ->assertJsonPath('data.can.invite', false);

        $this->getJson("/api/workers/{$inactive->id}")
            ->assertOk()
            ->assertJsonPath('data.registered_at', null)
            ->assertJsonPath('data.can.invite', false);

        // 一覧も同じ形
        $rows = collect($this->getJson('/api/workers')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame($usable->expires_at->toIso8601String(), $rows[$invited->id]['invitation_expires_at']);
        $this->assertNull($rows[$expired->id]['invitation_expires_at']);
        $this->assertSame(['invite' => false], $rows[$registered->id]['can']);
        $this->assertSame(['invite' => false], $rows[$inactive->id]['can']);
    }

    public function test_can_inviteは未登録かつ有効のときだけtrue(): void
    {
        $cases = [
            'unregistered_active' => [Worker::factory()->create(), true],
            'unregistered_inactive' => [Worker::factory()->inactive()->create(), false],
            'registered_active' => [Worker::factory()->registered()->create(), false],
            'registered_inactive' => [Worker::factory()->registered()->inactive()->create(), false],
        ];

        $this->actingAsAdmin();
        $rows = collect($this->getJson('/api/workers')->assertOk()->json('data'))->keyBy('id');

        foreach ($cases as $label => [$worker, $expected]) {
            $this->getJson("/api/workers/{$worker->id}")
                ->assertOk()
                ->assertJsonPath('data.can.invite', $expected);
            $this->assertSame(['invite' => $expected], $rows[$worker->id]['can'], $label);
        }
    }

    public function test_招待の期限ちょうどの招待は使えないものとして期限に出さない(): void
    {
        $this->freezeSecond();
        $atLimit = Worker::factory()->create();
        WorkerInvitation::factory()->create(['worker_id' => $atLimit->id, 'expires_at' => now()]);
        $oneSecondLeft = Worker::factory()->create();
        WorkerInvitation::factory()->create(['worker_id' => $oneSecondLeft->id, 'expires_at' => now()->addSecond()]);

        $this->actingAsAdmin();
        $this->getJson("/api/workers/{$atLimit->id}")->assertOk()->assertJsonPath('data.invitation_expires_at', null);
        $this->getJson("/api/workers/{$oneSecondLeft->id}")
            ->assertOk()
            ->assertJsonPath('data.invitation_expires_at', now()->addSecond()->toIso8601String());

        $rows = collect($this->getJson('/api/workers')->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($rows[$atLimit->id]['invitation_expires_at']);
        $this->assertSame(now()->addSecond()->toIso8601String(), $rows[$oneSecondLeft->id]['invitation_expires_at']);
    }

    public function test_使用済みの招待だけの未登録ワーカーは期限がnullで招待できる(): void
    {
        $worker = Worker::factory()->create();
        WorkerInvitation::factory()->used()->create(['worker_id' => $worker->id, 'expires_at' => now()->addDays(5)]);

        $this->actingAsAdmin()->getJson("/api/workers/{$worker->id}")
            ->assertOk()
            ->assertJsonPath('data.invitation_expires_at', null)
            ->assertJsonPath('data.can.invite', true);
    }

    public function test_使える招待が複数あれば一番新しい招待の期限を出す(): void
    {
        $this->freezeSecond();
        // サービスを通せば 1 件にそろうが、念のため id の最大を選ぶ。古い方の期限を遅くして「期限の最大」ではないことも確かめる
        $worker = Worker::factory()->create();
        WorkerInvitation::factory()->create(['worker_id' => $worker->id, 'expires_at' => now()->addDays(9)]);
        $latest = WorkerInvitation::factory()->create(['worker_id' => $worker->id, 'expires_at' => now()->addDays(2)]);
        // 一番新しい行が使えないなら、その前の使える招待（ここでは $latest）を選ぶ
        WorkerInvitation::factory()->revoked()->create(['worker_id' => $worker->id, 'expires_at' => now()->addDays(7)]);
        $expected = $latest->expires_at->toIso8601String();

        $this->actingAsAdmin();
        $this->getJson("/api/workers/{$worker->id}")->assertOk()->assertJsonPath('data.invitation_expires_at', $expected);
        $this->getJson('/api/workers')->assertOk()->assertJsonPath('data.0.invitation_expires_at', $expected);
    }

    public function test_他社のワーカーの招待の状態は詳細にも一覧にも出ない(): void
    {
        $this->freezeSecond();
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, function () {
            $worker = Worker::factory()->create();
            WorkerInvitation::factory()->create(['worker_id' => $worker->id, 'expires_at' => now()->addDays(4)]);

            return $worker;
        });
        $mine = Worker::factory()->create();
        Context::forget('company_id');

        $this->actingAsAdmin()->getJson("/api/workers/{$theirs->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりませんでした。', 'errors' => []]);

        $this->getJson('/api/workers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.invitation_expires_at', null);
    }

    public function test_ワーカー一覧のクエリ数は件数に比例しない(): void
    {
        $makeInvited = fn () => WorkerInvitation::factory()->count(3)->create();
        $makeInvited();
        $this->actingAsAdmin()->getJson('/api/workers')->assertOk();   // 認証などの初回分を済ませる

        DB::enableQueryLog();
        $this->getJson('/api/workers')->assertOk()->assertJsonCount(3, 'data');
        $few = count(DB::getQueryLog());

        $makeInvited();
        Worker::factory()->count(2)->create(); // 招待のないワーカーも混ぜる
        DB::flushQueryLog();
        $this->getJson('/api/workers')->assertOk()->assertJsonCount(8, 'data');
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($few, $many);
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

    // ---- メールの一意と小文字（docs/01 の 3.1・4.5） ----

    public function test_登録でメールを小文字で保存する(): void
    {
        Queue::fake();

        // 国際化メールアドレス（ローカル部に非 ASCII）。strtolower では Ä・Ö が小文字にならないので mb_strtolower で小文字にする
        $this->actingAsAdmin()
            ->postJson('/api/workers', ['name' => '山田太郎', 'email' => 'ÄÖ.Yamada@Example.COM'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'äö.yamada@example.com');

        $this->assertSame('äö.yamada@example.com', Worker::query()->sole()->email);
    }

    public function test_登録で同じ企業のほかのワーカーとメールが重なると422(): void
    {
        Queue::fake();
        // 未登録のワーカーとも重ならない（企業の中でメールは一意）。大文字小文字だけ違っても同じメール
        $existing = Worker::factory()->create(['email' => 'taken@example.com']);

        $this->actingAsAdmin()
            ->postJson('/api/workers', ['name' => '山田太郎', 'email' => 'Taken@Example.com'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'このメールアドレスは、この企業の別のワーカーに使われています。',
                'errors' => ['email' => ['このメールアドレスは、この企業の別のワーカーに使われています。']],
            ]);

        // ワーカーも招待も作られない
        $this->assertSame([$existing->id], Worker::query()->pluck('id')->all());
        $this->assertSame(0, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_登録で他社のワーカーと同じメールなら登録できる(): void
    {
        Queue::fake();
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, fn () => Worker::factory()->registered()->create(['email' => 'shared@example.com']));
        Context::forget('company_id');

        // 重複の確認は自社の中だけ（他社のワーカーの有無は分からない）
        $this->actingAsAdmin()
            ->postJson('/api/workers', ['name' => '山田太郎', 'email' => 'Shared@example.com'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'shared@example.com');
    }

    public function test_登録で確認の後に同じメールのワーカーが保存されても同じ422で何も残らない(): void
    {
        Queue::fake();

        // 重なりの確認（exists）の後、保存（INSERT）の前に、別のリクエストが同じメールのワーカーを保存した競合を再現する
        $inserted = false;
        Worker::saving(function (Worker $saving) use (&$inserted) {
            if ($inserted || $saving->name !== '山田太郎') {
                return;
            }
            $inserted = true;
            DB::table('workers')->insert([
                'company_id' => $this->company->id,
                'name' => '割り込み花子',
                'email' => 'race@example.com',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAsAdmin()
            ->postJson('/api/workers', ['name' => '山田太郎', 'email' => 'Race@Example.com'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'このメールアドレスは、この企業の別のワーカーに使われています。',
                'errors' => ['email' => ['このメールアドレスは、この企業の別のワーカーに使われています。']],
            ]);

        $this->assertTrue($inserted);
        // 山田太郎の行は残らず、招待も作られない。割り込みの行もテストでは同じトランザクションの中で入れているので一緒に巻き戻る
        // （本物の競合では相手のトランザクションが先にコミットしているので、相手の行は残る）
        $this->assertFalse(Worker::query()->where('name', '山田太郎')->exists());
        $this->assertSame(0, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_登録済みのワーカーのメールを直すと小文字で保存し招待は出ない(): void
    {
        Queue::fake();
        $worker = Worker::factory()->registered()->create(['name' => '山田太郎', 'email' => 'old@example.com']);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'email' => 'New.Yamada@Example.COM'])
            ->assertOk()
            ->assertJsonPath('data.email', 'new.yamada@example.com');

        $fresh = $worker->fresh();
        $this->assertSame('new.yamada@example.com', $fresh->email);
        $this->assertNotNull($fresh->registered_at);
        // 登録済みのワーカーには招待を出さない
        $this->assertSame(0, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_更新でも日本語を含むメールはウムラウトまで小文字で保存する(): void
    {
        Queue::fake();
        $worker = Worker::factory()->registered()->create(['name' => '山田太郎', 'email' => 'old@example.com']);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'email' => 'ÄÖ山田@Example.COM'])
            ->assertOk()
            ->assertJsonPath('data.email', 'äö山田@example.com');

        $this->assertSame('äö山田@example.com', $worker->fresh()->email);
    }

    public function test_更新で同じ企業のほかのワーカーとメールが重なると422(): void
    {
        Queue::fake();
        // 相手が未登録でも重なる（企業の中でメールは一意）
        Worker::factory()->create(['email' => 'taken@example.com']);
        $worker = Worker::factory()->registered()->create(['name' => '山田太郎', 'email' => 'yamada@example.com']);

        // 大文字小文字を変えても同じメールとして重なる
        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '新氏名', 'email' => 'Taken@Example.com'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'このメールアドレスは、この企業の別のワーカーに使われています。',
                'errors' => ['email' => ['このメールアドレスは、この企業の別のワーカーに使われています。']],
            ]);

        // 何も変わらない（氏名も巻き戻る）
        $fresh = $worker->fresh();
        $this->assertSame('山田太郎', $fresh->name);
        $this->assertSame('yamada@example.com', $fresh->email);
        Queue::assertNothingPushed();
    }

    public function test_登録で無効や登録済みのワーカーとメールが重なっても422(): void
    {
        Queue::fake();
        // 相手の状態（有効・無効、登録済み・未登録）を問わず、同じ企業の中でメールは一意（docs/01 の 3.1）
        $existing = [
            'unregistered_inactive' => Worker::factory()->inactive()->create(['email' => 'off@example.com']),
            'registered_active' => Worker::factory()->registered()->create(['email' => 'on@example.com']),
            'registered_inactive' => Worker::factory()->registered()->inactive()->create(['email' => 'both@example.com']),
        ];

        $this->actingAsAdmin();
        foreach ($existing as $label => $worker) {
            $this->postJson('/api/workers', ['name' => '山田太郎', 'email' => strtoupper($worker->email)])
                ->assertUnprocessable()
                ->assertExactJson([
                    'message' => 'このメールアドレスは、この企業の別のワーカーに使われています。',
                    'errors' => ['email' => ['このメールアドレスは、この企業の別のワーカーに使われています。']],
                ]);
            $this->assertSame(3, Worker::query()->count(), $label);
        }

        $this->assertSame(0, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_更新で無効のワーカーのメールにすると422(): void
    {
        Queue::fake();
        Worker::factory()->inactive()->create(['email' => 'off@example.com']);
        $worker = Worker::factory()->create(['name' => '山田太郎', 'email' => 'yamada@example.com']);
        $invitation = WorkerInvitation::factory()->create(['worker_id' => $worker->id]);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'email' => 'Off@Example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['email' => ['このメールアドレスは、この企業の別のワーカーに使われています。']]);

        $this->assertSame('yamada@example.com', $worker->fresh()->email);
        $this->assertTrue($invitation->fresh()->isUsable());
        Queue::assertNothingPushed();
    }

    public function test_未登録のワーカーでも同じ企業の登録済みワーカーのメールにすると422(): void
    {
        Queue::fake();
        Worker::factory()->registered()->create(['email' => 'taken@example.com']);
        $worker = Worker::factory()->create(['name' => '山田太郎', 'email' => 'yamada@example.com']);
        $invitation = WorkerInvitation::factory()->create(['worker_id' => $worker->id]);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'email' => 'TAKEN@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'このメールアドレスは、この企業の別のワーカーに使われています。')
            ->assertJsonPath('errors.email.0', 'このメールアドレスは、この企業の別のワーカーに使われています。');

        // メールも招待も変わらない（前の招待は使えるまま。新しい招待は積まれない）
        $this->assertSame('yamada@example.com', $worker->fresh()->email);
        $this->assertTrue($invitation->fresh()->isUsable());
        $this->assertSame(1, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_別の企業のワーカーと同じメールにしても422にならない(): void
    {
        Queue::fake();
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, function () {
            Worker::factory()->registered()->create(['email' => 'shared-a@example.com']);
            Worker::factory()->create(['email' => 'shared-b@example.com']);
        });
        $registered = Worker::factory()->registered()->create(['email' => 'registered@example.com']);
        $unregistered = Worker::factory()->create(['email' => 'unregistered@example.com']);

        // 重複の確認は自社の中だけ（他社のワーカーの有無は分からない）
        $this->actingAsAdmin()
            ->putJson("/api/workers/{$registered->id}", ['name' => '山田太郎', 'email' => 'Shared-A@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'shared-a@example.com');
        $this->assertSame('shared-a@example.com', $registered->fresh()->email);

        $this->putJson("/api/workers/{$unregistered->id}", ['name' => '鈴木花子', 'email' => 'shared-b@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'shared-b@example.com');
        $this->assertSame('shared-b@example.com', $unregistered->fresh()->email);
    }

    public function test_登録済みのワーカーのメールを大文字小文字だけ直しても小文字のまま招待は出ない(): void
    {
        Queue::fake();
        $worker = Worker::factory()->registered()->create(['name' => '山田太郎', 'email' => 'yamada@example.com']);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'email' => 'YAMADA@Example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'yamada@example.com');

        $this->assertSame('yamada@example.com', $worker->fresh()->email);
        $this->assertSame(0, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_更新で大文字小文字だけ違うメールは前の招待を残す(): void
    {
        Queue::fake();
        $worker = Worker::factory()->create(['name' => '山田太郎', 'email' => 'yamada@example.com']);
        $invitation = WorkerInvitation::factory()->create(['worker_id' => $worker->id]);

        // 保存も初回登録の照合も小文字なので、前の招待のコードはそのまま使える。取り消さず、送り直さない
        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'email' => 'Yamada@EXAMPLE.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'yamada@example.com')
            ->assertJsonPath('data.invitation_expires_at', $invitation->expires_at->toIso8601String());

        $this->assertSame('yamada@example.com', $worker->fresh()->email);
        $this->assertTrue($invitation->fresh()->isUsable());
        $this->assertSame(1, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_無効で登録済みのワーカーのメールを直すと小文字で保存し招待は出ない(): void
    {
        Queue::fake();
        $worker = Worker::factory()->inactive()->registered()->create(['name' => '山田太郎', 'email' => 'old@example.com']);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田太郎', 'email' => 'New@Example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.invitation_expires_at', null)
            ->assertJsonPath('data.can.invite', false);

        $fresh = $worker->fresh();
        $this->assertSame('new@example.com', $fresh->email);
        $this->assertFalse($fresh->is_active);
        $this->assertNotNull($fresh->registered_at);
        $this->assertSame(0, WorkerInvitation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_登録済みのワーカーが自分のメールのまま他の項目を直しても422にならない(): void
    {
        Queue::fake();
        $worker = Worker::factory()->registered()->create(['name' => '山田太郎', 'email' => 'yamada@example.com', 'phone' => null]);

        // 重なりの確認は自分自身を除く
        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '山田一郎', 'email' => 'yamada@example.com', 'phone' => '090-1111-2222'])
            ->assertOk()
            ->assertJsonPath('data.name', '山田一郎')
            ->assertJsonPath('data.phone', '090-1111-2222');

        $this->assertSame('yamada@example.com', $worker->fresh()->email);
        Queue::assertNothingPushed();
    }

    public function test_メールが重なって422のときは未登録のワーカーの氏名と電話も巻き戻る(): void
    {
        Queue::fake();
        Worker::factory()->registered()->create(['email' => 'taken@example.com']);
        $worker = Worker::factory()->create(['name' => '山田太郎', 'email' => 'yamada@example.com', 'phone' => '090-0000-0000']);

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '新氏名', 'email' => 'taken@example.com', 'phone' => '080-9999-9999'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'このメールアドレスは、この企業の別のワーカーに使われています。');

        $fresh = $worker->fresh();
        $this->assertSame('山田太郎', $fresh->name);
        $this->assertSame('090-0000-0000', $fresh->phone);
        $this->assertSame('yamada@example.com', $fresh->email);
    }

    public function test_更新で確認の後に別のワーカーが同じメールになっても同じ422の文言で何も変わらない(): void
    {
        Queue::fake();
        $worker = Worker::factory()->registered()->create(['name' => '山田太郎', 'email' => 'yamada@example.com', 'phone' => null]);

        // 重なりの確認（exists）の後、保存（UPDATE）の前に、別のリクエストが同じメールのワーカーを保存した競合を再現する。
        // saving はサービスの save() の直前に呼ばれるので、ここで同じ企業・同じ email の行を直接入れる
        $inserted = false;
        Worker::saving(function (Worker $saving) use ($worker, &$inserted) {
            if ($inserted || $saving->id !== $worker->id) {
                return;
            }
            $inserted = true;
            DB::table('workers')->insert([
                'company_id' => $this->company->id,
                'name' => '割り込み花子',
                'email' => 'race@example.com',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$worker->id}", ['name' => '新氏名', 'email' => 'Race@Example.com', 'phone' => '090-1111-2222'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'このメールアドレスは、この企業の別のワーカーに使われています。',
                'errors' => ['email' => ['このメールアドレスは、この企業の別のワーカーに使われています。']],
            ]);

        $this->assertTrue($inserted);
        // ワーカーの更新はすべて巻き戻る。割り込みの行もテストでは同じトランザクションの中で入れているので一緒に巻き戻る
        // （本物の競合では相手のトランザクションが先にコミットしているので、相手の行は残る）
        $fresh = $worker->fresh();
        $this->assertSame('山田太郎', $fresh->name);
        $this->assertNull($fresh->phone);
        $this->assertSame('yamada@example.com', $fresh->email);
        $this->assertSame(1, Worker::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_他社のワーカーのメールを変えようとすると404で何も変わらない(): void
    {
        Queue::fake();
        $other = Company::factory()->create();
        [$registered, $unregistered, $invitation] = CurrentCompany::runAs($other->id, function () {
            $registered = Worker::factory()->registered()->create(['email' => 'theirs-a@example.com']);
            $unregistered = Worker::factory()->create(['email' => 'theirs-b@example.com']);
            $invitation = WorkerInvitation::factory()->create(['worker_id' => $unregistered->id]);

            return [$registered, $unregistered, $invitation];
        });
        Context::forget('company_id');

        $this->actingAsAdmin()
            ->putJson("/api/workers/{$registered->id}", ['name' => '乗っ取り', 'email' => 'mine@example.com'])
            ->assertNotFound();
        $this->putJson("/api/workers/{$unregistered->id}", ['name' => '乗っ取り', 'email' => 'mine@example.com'])
            ->assertNotFound();

        $this->assertDatabaseHas('workers', ['id' => $registered->id, 'email' => 'theirs-a@example.com']);
        $this->assertDatabaseHas('workers', ['id' => $unregistered->id, 'email' => 'theirs-b@example.com']);
        $this->assertDatabaseHas('worker_invitations', ['id' => $invitation->id, 'revoked_at' => null]);
        $this->assertDatabaseCount('worker_invitations', 1);
        Queue::assertNothingPushed();
    }
}
