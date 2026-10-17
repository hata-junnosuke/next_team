<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Enums\ReportExportStatus;
use App\Models\Application;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\Worker;
use App\Support\CurrentCompany;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * 非同期の土台（docs/01 の非同期用のテーブル・フレームワークのテーブル・テナント分離・モデル）を DB とモデルに直接当てて確かめる。
 * - マイグレーション: 索引・UNIQUE・既定値・NOT NULL・外部キー
 * - morph map の強制: *_type にクラス名が入らない、未登録のモデルは例外
 * - テナント分離: NotificationLog / ReportExport のグローバルスコープ、他社の管理者を依頼者にできない
 * - Fillable: company_id と status（と ReportExport の成果物の列）は一括代入できない
 * API はまだないので HTTP は叩かない（ReportExportTest で叩く）。
 */
class AsyncFoundationTest extends TestCase
{
    // --- マイグレーション ---------------------------------------------------

    public function test_docs01の3_2の索引と一意制約がある(): void
    {
        $expected = [
            'notification_logs' => [['company_id'], ['company_id', 'created_at'], ['recipient_type', 'recipient_id'], ['subject_type', 'subject_id']],
            'report_exports' => [['company_id'], ['user_id'], ['company_id', 'created_at'], ['company_id', 'month']],
        ];
        foreach ($expected as $table => $indexes) {
            $actual = array_map(fn (array $index) => $index['columns'], Schema::getIndexes($table));
            foreach ($indexes as $columns) {
                $this->assertContains($columns, $actual, "{$table} に (".implode(', ', $columns).') の索引がない');
            }
        }

        $uniques = fn (string $table) => array_values(array_map(
            fn (array $index) => $index['columns'],
            array_filter(Schema::getIndexes($table), fn (array $index) => $index['unique'] && ! $index['primary']),
        ));
        $this->assertSame([['dedupe_key']], $uniques('notification_logs'));
        // 同じ月の出力は作り直せる（生成中の二重依頼はサービスが 422 で止める。DB では一意にしない）
        $this->assertSame([], $uniques('report_exports'));
    }

    public function test_docs01の3_2の列がある(): void
    {
        $this->assertTrue(Schema::hasColumns('notification_logs', [
            'id', 'company_id', 'kind', 'recipient_type', 'recipient_id', 'subject_type', 'subject_id',
            'recipient_email', 'dedupe_key', 'status', 'sent_at', 'failed_at', 'error', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('report_exports', [
            'id', 'company_id', 'user_id', 'month', 'status', 'batch_id', 'file_path', 'row_count',
            'completed_at', 'failed_at', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('notifications', ['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at']));
        $this->assertTrue(Schema::hasTable('job_batches'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
    }

    public function test_dbの既定値と必須はdocs01の3_2どおり(): void
    {
        $nullable = fn (string $table) => collect(Schema::getColumns($table))->mapWithKeys(fn (array $c) => [$c['name'] => $c['nullable']]);

        $logs = $nullable('notification_logs');
        foreach (['company_id', 'kind', 'recipient_type', 'recipient_id', 'subject_type', 'subject_id', 'recipient_email', 'dedupe_key', 'status'] as $column) {
            $this->assertFalse($logs[$column], "notification_logs.{$column} は NOT NULL");
        }
        foreach (['sent_at', 'failed_at', 'error'] as $column) {
            $this->assertTrue($logs[$column], "notification_logs.{$column} は NULL 可");
        }

        $exports = $nullable('report_exports');
        foreach (['company_id', 'user_id', 'month', 'status'] as $column) {
            $this->assertFalse($exports[$column], "report_exports.{$column} は NOT NULL");
        }
        foreach (['batch_id', 'file_path', 'row_count', 'completed_at', 'failed_at'] as $column) {
            $this->assertTrue($exports[$column], "report_exports.{$column} は NULL 可");
        }

        // status を渡さずに入れると、DB の既定値（pending / queued）になる
        $logId = DB::table('notification_logs')->insertGetId($this->logRow('application_confirmed:application:1'));
        $exportId = DB::table('report_exports')->insertGetId([
            'company_id' => $this->company->id, 'user_id' => $this->admin->id, 'month' => '2026-09',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('pending', DB::table('notification_logs')->where('id', $logId)->value('status'));
        $this->assertSame('queued', DB::table('report_exports')->where('id', $exportId)->value('status'));
    }

    public function test_宛先のメールアドレスがない通知の記録はdbが拒否する(): void
    {
        $row = $this->logRow('application_confirmed:application:1');
        $row['recipient_email'] = null;

        $this->expectException(QueryException::class);

        DB::table('notification_logs')->insert($row);
    }

    public function test_存在しない管理者を依頼者にした集計出力はdbが拒否する(): void
    {
        $this->expectException(QueryException::class);

        DB::table('report_exports')->insert([
            'company_id' => $this->company->id, 'user_id' => 999999, 'month' => '2026-09',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_集計出力を依頼した管理者はdbでも削除できない(): void
    {
        ReportExport::factory()->create(['user_id' => $this->admin->id]);

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $this->admin->id)->delete();
    }

    public function test_通知の記録がある企業はdbでも削除できない(): void
    {
        $other = Company::factory()->create();
        DB::table('notification_logs')->insert([...$this->logRow('application_confirmed:application:1'), 'company_id' => $other->id]);

        $this->expectException(QueryException::class);

        DB::table('companies')->where('id', $other->id)->delete();
    }

    // --- morph map ----------------------------------------------------------

    public function test_morph_mapはdocs01の4_3の5モデルで強制されている(): void
    {
        $this->assertTrue(Relation::requiresMorphMap());
        $this->assertEqualsCanonicalizing([
            'worker' => Worker::class,
            'user' => User::class,
            'application' => Application::class,
            'job_posting' => JobPosting::class,
            'report_export' => ReportExport::class,
        ], Relation::morphMap());
    }

    public function test_登録したモデルは短い名前になりクラス名にならない(): void
    {
        $expected = [
            'worker' => new Worker,
            'user' => new User,
            'application' => new Application,
            'job_posting' => new JobPosting,
            'report_export' => new ReportExport,
        ];
        foreach ($expected as $alias => $model) {
            $this->assertSame($alias, $model->getMorphClass());
        }
    }

    public function test_morph_mapにないモデルをポリモーフィックに使うと例外(): void
    {
        foreach ([new Location, new Attendance, new Company, new NotificationLog] as $model) {
            try {
                $model->getMorphClass();
                $this->fail(class_basename($model).' がクラス名のまま使えてしまった');
            } catch (ClassMorphViolationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_ファクトリで作った通知の記録にもクラス名が入らない(): void
    {
        NotificationLog::factory()->create();
        NotificationLog::factory()->sent()->create();
        NotificationLog::factory()->failed()->create();
        NotificationLog::factory()->forExport()->create();

        $types = DB::table('notification_logs')->get(['recipient_type', 'subject_type']);
        foreach ($types as $row) {
            $this->assertStringNotContainsString('\\', $row->recipient_type);
            $this->assertStringNotContainsString('\\', $row->subject_type);
        }
        $this->assertSame(4, $types->count());
    }

    public function test_出力完了のファクトリは管理者宛てで対象は集計出力になりキーも本番と同じ形(): void
    {
        $log = NotificationLog::factory()->forExport()->create();

        $export = $log->subject;
        $this->assertInstanceOf(ReportExport::class, $export);
        $this->assertTrue($log->recipient->is($export->user));
        $this->assertSame($export->user->email, $log->recipient_email);
        $this->assertSame("export_ready:report_export:{$export->id}", $log->dedupe_key);
        $this->assertSame(['user', 'report_export'], [$log->getRawOriginal('recipient_type'), $log->getRawOriginal('subject_type')]);
    }

    public function test_管理者へのアプリ内通知のnotifiable_typeは短い名前(): void
    {
        $this->admin->notify(new class extends Notification
        {
            /** @return list<string> */
            public function via(object $notifiable): array
            {
                return ['database'];
            }

            /** @return array<string, mixed> */
            public function toArray(object $notifiable): array
            {
                return ['message' => '集計の出力が完了しました。'];
            }

            public function databaseType(object $notifiable): string
            {
                return 'export_ready';
            }
        });

        $row = DB::table('notifications')->first();
        $this->assertSame('user', $row->notifiable_type);
        $this->assertSame($this->admin->id, (int) $row->notifiable_id);
        $this->assertSame(1, $this->admin->notifications()->count());
    }

    // --- テナント分離 --------------------------------------------------------

    public function test_他社の通知の記録と集計出力は見えない(): void
    {
        $other = Company::factory()->create();
        [$theirLog, $theirExport] = CurrentCompany::runAs($other->id, fn () => [
            NotificationLog::factory()->create(),
            ReportExport::factory()->create(),
        ]);
        $myLog = NotificationLog::factory()->create();
        $myExport = ReportExport::factory()->create();

        $this->assertNull(NotificationLog::query()->find($theirLog->id));
        $this->assertNull(ReportExport::query()->find($theirExport->id));
        $this->assertSame([$myLog->id], NotificationLog::query()->pluck('id')->all());
        $this->assertSame([$myExport->id], ReportExport::query()->pluck('id')->all());
        $this->assertSame($other->id, $theirLog->company_id);
        $this->assertSame($other->id, $theirExport->company_id);
    }

    public function test_作成時に他社のcompany_idを渡しても自社で作られる(): void
    {
        $other = Company::factory()->create();

        $export = ReportExport::create(['user_id' => $this->admin->id, 'month' => '2026-09', 'company_id' => $other->id]);
        $log = (new NotificationLog)->forceFill([...$this->logRow('application_confirmed:application:1'), 'company_id' => $other->id]);
        $log->save();

        $this->assertSame($this->company->id, $export->fresh()->company_id);
        $this->assertSame($this->company->id, $log->fresh()->company_id);
    }

    public function test_company_idを他社に付け替えるとlogic_exceptionで保存されない(): void
    {
        $other = Company::factory()->create();
        $models = [NotificationLog::factory()->create(), ReportExport::factory()->create()];

        foreach ($models as $model) {
            try {
                $model->forceFill(['company_id' => $other->id])->save();
                $this->fail(class_basename($model).' の company_id を付け替えられてしまった');
            } catch (LogicException) {
                $this->assertSame($this->company->id, $model->fresh()->company_id);
            }
        }
    }

    public function test_他社の管理者を依頼者にした集計出力は作れない(): void
    {
        $other = Company::factory()->create();
        $otherAdmin = User::factory()->for($other)->create();

        try {
            ReportExport::create(['user_id' => $otherAdmin->id, 'month' => '2026-09']);
            $this->fail('他社の管理者を依頼者にできてしまった');
        } catch (LogicException) {
            $this->assertSame(0, ReportExport::query()->count());
        }
    }

    public function test_集計出力の依頼者を他社の管理者に付け替えられない(): void
    {
        $other = Company::factory()->create();
        $otherAdmin = User::factory()->for($other)->create();
        $export = ReportExport::factory()->create(['user_id' => $this->admin->id]);

        try {
            $export->update(['user_id' => $otherAdmin->id]);
            $this->fail('依頼者を他社の管理者に付け替えられてしまった');
        } catch (LogicException) {
            $this->assertSame($this->admin->id, $export->fresh()->user_id);
        }
    }

    public function test_企業が未設定のまま通知の記録と集計出力を検索するとlogic_exception(): void
    {
        Context::forget('company_id');

        foreach ([NotificationLog::class, ReportExport::class] as $class) {
            try {
                $class::query()->count();
                $this->fail("{$class} が企業なしで検索できてしまった");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- Fillable -----------------------------------------------------------

    public function test_通知の記録のcompany_idとstatusと送信結果の列はfillableではない(): void
    {
        $log = new NotificationLog;

        foreach (['company_id', 'status', 'sent_at', 'failed_at', 'error'] as $column) {
            $this->assertFalse($log->isFillable($column), "notification_logs.{$column} が fillable");
        }
    }

    public function test_集計出力はuser_idとmonthだけがfillable(): void
    {
        $export = new ReportExport;

        $this->assertEqualsCanonicalizing(['user_id', 'month'], $export->getFillable());
        foreach (['company_id', 'status', 'batch_id', 'file_path', 'row_count', 'completed_at', 'failed_at'] as $column) {
            $this->assertFalse($export->isFillable($column), "report_exports.{$column} が fillable");
        }
    }

    public function test_createとupdateでは状態と成果物の列を変えられない(): void
    {
        $export = ReportExport::create([
            'user_id' => $this->admin->id, 'month' => '2026-09',
            'status' => 'completed', 'file_path' => '../../.env', 'row_count' => 99, 'batch_id' => 'x',
        ]);
        $export->update(['status' => 'failed', 'file_path' => '/etc/passwd', 'completed_at' => now()]);

        $fresh = $export->fresh();
        $this->assertSame(ReportExportStatus::Queued, $fresh->status);
        $this->assertNull($fresh->file_path);
        $this->assertNull($fresh->row_count);
        $this->assertNull($fresh->batch_id);
        $this->assertNull($fresh->completed_at);

        $log = NotificationLog::factory()->create();
        $log->update(['status' => 'sent', 'sent_at' => now(), 'error' => '書き換え']);

        $freshLog = $log->fresh();
        $this->assertSame(NotificationStatus::Pending, $freshLog->status);
        $this->assertNull($freshLog->sent_at);
        $this->assertNull($freshLog->error);
    }

    /**
     * モデルを通さずに入れる通知の記録の 1 行（status は入れない = DB の既定値を使う）
     *
     * @return array<string, mixed>
     */
    private function logRow(string $dedupeKey): array
    {
        return [
            'company_id' => $this->company->id,
            'kind' => 'application_confirmed',
            'recipient_type' => 'worker',
            'recipient_id' => 1,
            'subject_type' => 'application',
            'subject_id' => 1,
            'recipient_email' => 'worker@example.com',
            'dedupe_key' => $dedupeKey,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
