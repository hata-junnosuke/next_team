<?php

namespace Tests\Feature;

use App\Enums\NotificationKind;
use App\Models\Company;
use App\Models\Worker;
use App\Models\WorkerInvitation;
use App\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * ワーカーアプリの土台（docs/01 の 3.1 の企業コード・workers に足す列、3.2 の worker_invitations、4.1 の NotificationKind）を
 * DB とモデルに直接当てて確かめる。HTTP は叩かない（API は WorkerAuthTest・WorkerInvitationTest などで叩く）。
 */
class WorkerFoundationTest extends TestCase
{
    // --- 企業コード（3.1） ---------------------------------------------------

    public function test_企業を作ると紛らわしい文字を除いた6文字の企業コードが入る(): void
    {
        $companies = Company::factory()->count(30)->create();

        foreach ([$this->company, ...$companies] as $company) {
            // 0 O 1 I L を除いた英大文字と数字の 6 文字
            $this->assertMatchesRegularExpression('/\A[A-HJKMNP-Z2-9]{6}\z/', $company->code);
            $this->assertSame($company->code, DB::table('companies')->where('id', $company->id)->value('code'));
        }
    }

    public function test_企業コードはdbでも重複できない(): void
    {
        $this->expectException(QueryException::class);

        DB::table('companies')->insert([
            'name' => '重複株式会社', 'code' => $this->company->code, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_企業コードはdbでもnullにできない(): void
    {
        $this->expectException(QueryException::class);

        DB::table('companies')->insert(['name' => 'コードなし株式会社', 'code' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_企業コードのマイグレーションは既存の企業を埋めてからnot_nullとuniqueにする(): void
    {
        $migration = require database_path('migrations/2026_11_09_100000_add_code_to_companies_table.php');

        // SQLite の change() は表を作り直す（一時の表に写して元の表を消す）。本物の migrate は外部キーを切って動くが、
        // テストは RefreshDatabase のトランザクションの中で PRAGMA foreign_keys = OFF が効かないので、企業を参照する行を先に消す
        // （参照する行がある DB での migrate と rollback は手で確かめた。下で users の外部キーが残ることだけ確かめる）
        DB::table('users')->delete();

        // 列を足す前の状態に戻し、コードのない既存の企業を用意する
        $migration->down();
        $this->assertFalse(Schema::hasColumn('companies', 'code'));
        foreach (range(1, 20) as $i) {
            DB::table('companies')->insert(['name' => "既存{$i}株式会社", 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration->up();

        $codes = DB::table('companies')->pluck('code', 'id');
        $this->assertCount(21, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/\A[A-HJKMNP-Z2-9]{6}\z/', (string) $code);
        }
        $this->assertCount(21, array_unique($codes->all()));
        // 作り直した後も、企業を参照する外部キーは残っている
        $this->assertContains('companies', array_column(Schema::getForeignKeys('users'), 'foreign_table'));

        // 埋めた後は NOT NULL と UNIQUE が効く
        try {
            DB::table('companies')->insert(['name' => 'コードなし', 'code' => null, 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('code が NULL の企業を作れてしまった');
        } catch (QueryException) {
        }
        $this->expectException(QueryException::class);
        DB::table('companies')->insert(['name' => '重複', 'code' => $codes->first(), 'created_at' => now(), 'updated_at' => now()]);
    }

    // --- workers のログイン列（3.1） ------------------------------------------

    public function test_同じ企業でメールが重なるワーカーは保存できない(): void
    {
        // 未登録でも縛る（企業の中でメールは一意。docs/01 の 3.1）
        Worker::factory()->create(['email' => 'same@example.com']);

        $this->expectException(QueryException::class);

        Worker::factory()->create(['email' => 'same@example.com']);
    }

    public function test_ワーカーのメールのマイグレーションは既存の行を小文字にしてからuniqueにする(): void
    {
        $migration = require database_path('migrations/2026_11_09_100100_add_login_columns_to_workers_table.php');
        $other = Company::factory()->create();

        // 列と一意制約を足す前に戻し、大文字を含むメールの既存の行を用意する（他社とは小文字にしても重なってよい）
        $migration->down();
        $this->assertFalse(Schema::hasColumn('workers', 'password'));
        $insert = fn (int $companyId, string $email) => DB::table('workers')->insertGetId([
            'company_id' => $companyId, 'name' => '既存', 'email' => $email, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mixed = $insert($this->company->id, 'Taro.Yamada@Example.COM');
        $umlaut = $insert($this->company->id, 'ÄÖ山田@Example.com');
        $lower = $insert($this->company->id, 'hanako@example.com');
        $theirs = $insert($other->id, 'TARO.YAMADA@example.com');

        $migration->up();

        $emails = DB::table('workers')->pluck('email', 'id');
        $this->assertSame('taro.yamada@example.com', $emails[$mixed]);
        $this->assertSame('äö山田@example.com', $emails[$umlaut]);
        $this->assertSame('hanako@example.com', $emails[$lower]);
        $this->assertSame('taro.yamada@example.com', $emails[$theirs]);
        $this->assertTrue(Schema::hasColumn('workers', 'password'));
        $this->assertTrue(Schema::hasColumn('workers', 'registered_at'));

        // 小文字にした後は同じ企業の中で一意
        $this->expectException(QueryException::class);
        $insert($this->company->id, 'taro.yamada@example.com');
    }

    public function test_ワーカーのメールのマイグレーションは小文字にして重なる行があれば失敗する(): void
    {
        $migration = require database_path('migrations/2026_11_09_100100_add_login_columns_to_workers_table.php');
        $migration->down();
        $ids = array_map(fn (string $email) => DB::table('workers')->insertGetId([
            'company_id' => $this->company->id, 'name' => '既存', 'email' => $email, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]), ['same@example.com', 'Same@Example.com']);

        // 黙って片方を消したり残したりせず、書き込みと DDL の前の検査で止まる（docs/01 の 3.1）。
        // 例外の文言に企業 ID と行 ID が入り、どの行を直せばよいかが分かる
        try {
            $migration->up();
            $this->fail('重なる行があるのにマイグレーションが通った');
        } catch (RuntimeException $e) {
            // QueryException も RuntimeException の子なので、DB の失敗（UNIQUE の追加）ではないことも確かめる
            $this->assertNotInstanceOf(QueryException::class, $e);
            $this->assertStringContainsString("company_id={$this->company->id}", $e->getMessage());
            $this->assertStringContainsString("{$ids[0]}, {$ids[1]}", $e->getMessage());
        }

        // 何も書き込まれていない（列は増えず、メールも小文字にしていない）。MySQL は DDL が自動コミットなので、途中まで進むと再実行もできなくなる
        $this->assertFalse(Schema::hasColumn('workers', 'password'));
        $this->assertFalse(Schema::hasColumn('workers', 'registered_at'));
        $this->assertSame('Same@Example.com', DB::table('workers')->where('id', $ids[1])->value('email'));
    }

    public function test_ワーカーのメールはどの経路で保存しても小文字になる(): void
    {
        // サービスを通さない経路（ファクトリ・モデルの update・forceFill・new）でも小文字で入る（Worker の email のミューテタ）
        $created = Worker::factory()->create(['email' => 'ÄÖ.Created@Example.COM']);
        $this->assertSame('äö.created@example.com', $created->email);
        $this->assertDatabaseHas('workers', ['id' => $created->id, 'email' => 'äö.created@example.com']);

        $created->update(['email' => 'Updated@Example.COM']);
        $this->assertDatabaseHas('workers', ['id' => $created->id, 'email' => 'updated@example.com']);

        $created->forceFill(['email' => 'Forced@Example.COM'])->save();
        $this->assertDatabaseHas('workers', ['id' => $created->id, 'email' => 'forced@example.com']);

        $made = new Worker(['name' => '山田太郎', 'email' => 'New@Example.COM']);
        $this->assertSame('new@example.com', $made->email);
    }

    public function test_他社なら同じメールのワーカーを保存できる(): void
    {
        $other = Company::factory()->create();

        $mine = Worker::factory()->create(['email' => 'same@example.com']);
        $theirs = CurrentCompany::runAs($other->id, fn () => Worker::factory()->registered()->create(['email' => 'same@example.com']));

        $this->assertSame('same@example.com', $mine->email);
        $this->assertSame('same@example.com', $theirs->email);
        $this->assertNotSame($mine->company_id, $theirs->company_id);
    }

    public function test_パスワードと登録日時は一括代入できずパスワードは配列に出ない(): void
    {
        $worker = Worker::create([
            'name' => '山田太郎',
            'email' => 'yamada@example.com',
            'password' => 'secret-password',
            'registered_at' => now(),
        ]);

        $this->assertNull($worker->password);
        $this->assertNull($worker->registered_at);
        $this->assertDatabaseHas('workers', ['id' => $worker->id, 'password' => null, 'registered_at' => null]);

        // サービスは forceFill で入れる。password は hashed cast でハッシュになり、配列（JSON）には出ない
        $worker->forceFill(['password' => 'secret-password', 'registered_at' => now()])->save();
        $stored = (string) DB::table('workers')->where('id', $worker->id)->value('password');

        $this->assertNotSame('secret-password', $stored);
        $this->assertTrue(Hash::check('secret-password', $stored));
        $this->assertArrayNotHasKey('password', $worker->fresh()->toArray());
    }

    // --- worker_invitations（3.2） --------------------------------------------

    public function test_招待のコードのハッシュはdbでも一意(): void
    {
        $invitation = WorkerInvitation::factory()->create();

        $this->expectException(QueryException::class);

        WorkerInvitation::factory()->create(['code_hash' => $invitation->code_hash]);
    }

    public function test_招待のあるワーカーはdbでも削除できない(): void
    {
        $invitation = WorkerInvitation::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('workers')->where('id', $invitation->worker_id)->delete();
    }

    public function test_招待が使えるのは未使用かつ未取消かつ期限前のときだけ(): void
    {
        $this->travelTo('2026-11-09 12:00:00');

        $usable = WorkerInvitation::factory()->create(['expires_at' => '2026-11-09 12:00:01']);
        $used = WorkerInvitation::factory()->create(['expires_at' => '2026-11-16 12:00:00', 'used_at' => '2026-11-09 11:00:00']);
        $revoked = WorkerInvitation::factory()->create(['expires_at' => '2026-11-16 12:00:00', 'revoked_at' => '2026-11-09 11:00:00']);
        $expiresNow = WorkerInvitation::factory()->create(['expires_at' => '2026-11-09 12:00:00']);
        $expired = WorkerInvitation::factory()->create(['expires_at' => '2026-11-09 11:59:59']);

        $this->assertTrue($usable->isUsable());
        $this->assertFalse($used->isUsable());
        $this->assertFalse($revoked->isUsable());
        // 期限ちょうどはもう使えない（「期限が未来のもの」だけ）
        $this->assertFalse($expiresNow->isUsable());
        $this->assertFalse($expired->isUsable());
    }

    public function test_他社の招待は見えず作成時の企業は今の企業で埋まる(): void
    {
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => WorkerInvitation::factory()->create());
        $mine = WorkerInvitation::factory()->create();

        $this->assertSame($this->company->id, $mine->company_id);
        $this->assertSame($other->id, $theirs->company_id);
        $this->assertSame([$mine->id], WorkerInvitation::query()->pluck('id')->all());
        $this->assertNull(WorkerInvitation::query()->find($theirs->id));
    }

    public function test_招待のコードは暗号化して保存される(): void
    {
        $invitation = WorkerInvitation::factory()->create(['code' => 'ABCD2345']);
        $stored = (string) DB::table('worker_invitations')->where('id', $invitation->id)->value('code');

        $this->assertStringNotContainsString('ABCD2345', $stored);
        $this->assertSame('ABCD2345', $invitation->fresh()->code);
        // 引くためのハッシュは HMAC（用途の接頭辞つき）で、入力の空白と小文字を直してから計算する
        $this->assertSame(hash_hmac('sha256', 'ABCD2345', 'worker-invitation:'.config('app.key')), $invitation->code_hash);
        $this->assertSame($invitation->code_hash, WorkerInvitation::hashCode(' abcd2345 '));
    }

    public function test_招待の必須の列はdbでもnullにできずコードだけはnullにできる(): void
    {
        $worker = Worker::factory()->create();
        $row = fn (array $override) => [
            'company_id' => $this->company->id,
            'worker_id' => $worker->id,
            'code_hash' => WorkerInvitation::hashCode(uniqid()),
            'code' => null,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
            ...$override,
        ];

        // code は使用済み・取り消しで NULL にする列なので NULL でよい
        DB::table('worker_invitations')->insert($row([]));
        $this->assertSame(1, DB::table('worker_invitations')->whereNull('code')->count());

        foreach (['company_id', 'worker_id', 'code_hash', 'expires_at'] as $column) {
            try {
                DB::table('worker_invitations')->insert($row([$column => null]));
                $this->fail("{$column} が NULL の招待を作れてしまった");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_招待の外部キーは企業とワーカーをどちらも削除時に止める(): void
    {
        // 企業の行だけを消す状況は、企業を参照するほかの表（users・workers）も止めるので HTTP やデータでは切り分けられない。定義を直接見る
        $foreignKeys = collect(Schema::getForeignKeys('worker_invitations'))
            ->mapWithKeys(fn (array $fk) => [$fk['columns'][0] => [$fk['foreign_table'], strtolower((string) $fk['on_delete'])]])
            ->all();

        $this->assertSame([
            'company_id' => ['companies', 'restrict'],
            'worker_id' => ['workers', 'restrict'],
        ], collect($foreignKeys)->sortKeys()->all());
        // worker_id と company_id には索引がある（SQLite は外部キーに索引を自動で作らない）
        $indexed = collect(Schema::getIndexes('worker_invitations'))->pluck('columns')->all();
        $this->assertContains(['worker_id'], $indexed);
        $this->assertContains(['company_id'], $indexed);
        $this->assertContains(['code_hash'], $indexed);
    }

    public function test_他社のワーカーへの招待は作れない(): void
    {
        $other = Company::factory()->create();
        $theirWorker = CurrentCompany::runAs($other->id, fn () => Worker::factory()->create());

        try {
            WorkerInvitation::factory()->create(['worker_id' => $theirWorker->id]);
            $this->fail('他社のワーカーへの招待を作れてしまった');
        } catch (LogicException) {
            $this->assertSame(0, DB::table('worker_invitations')->count());
        }
    }

    public function test_招待のコードとハッシュは配列に出ず使用済みと取消と企業は一括代入できない(): void
    {
        $other = Company::factory()->create();
        $worker = Worker::factory()->create();

        $invitation = WorkerInvitation::create([
            'worker_id' => $worker->id,
            'code' => 'ABCD2345',
            'code_hash' => WorkerInvitation::hashCode('ABCD2345'),
            'expires_at' => now()->addDays(7),
            'used_at' => now(),
            'revoked_at' => now(),
            'company_id' => $other->id,
        ])->fresh();

        $this->assertNull($invitation->used_at);
        $this->assertNull($invitation->revoked_at);
        $this->assertSame($this->company->id, $invitation->company_id);
        $this->assertTrue($invitation->isUsable());

        $array = $invitation->toArray();
        $this->assertArrayNotHasKey('code', $array);
        $this->assertArrayNotHasKey('code_hash', $array);
        $this->assertStringNotContainsString('ABCD2345', $invitation->toJson());
    }

    public function test_ワーカーの招待は送り直すたびに増え関連とmorph_mapの名前で引ける(): void
    {
        $worker = Worker::factory()->create();
        $first = WorkerInvitation::factory()->revoked()->for($worker)->create();
        $second = WorkerInvitation::factory()->for($worker)->create();
        WorkerInvitation::factory()->create(); // 別のワーカーの招待は混ざらない

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $worker->invitations()->pluck('id')->all());
        $this->assertSame([$second->id], $worker->invitations->filter->isUsable()->pluck('id')->values()->all());
        $this->assertSame($worker->id, $second->worker->id);
        $this->assertSame('worker_invitation', $second->getMorphClass());
    }

    public function test_招待とトークンの有効期限の日数の既定は7日と30日で招待のファクトリは7日後(): void
    {
        $this->travelTo('2026-11-09 12:00:00');

        $this->assertSame(7, config('myteam.worker_invitation_days'));
        $this->assertSame(30, config('myteam.worker_token_days'));
        $this->assertSame('2026-11-16 12:00:00', WorkerInvitation::factory()->create()->expires_at->format('Y-m-d H:i:s'));
    }

    // --- NotificationKind（4.1） ----------------------------------------------

    public function test_通知の種類に招待がありラベルは招待(): void
    {
        $this->assertSame(NotificationKind::WorkerInvitation, NotificationKind::from('worker_invitation'));
        $this->assertSame('招待', NotificationKind::WorkerInvitation->label());
    }
}
