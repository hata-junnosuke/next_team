<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Attendance;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\Worker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P2-01 の業務テーブルの制約（docs/01 3.1・3.4）を DB に直接当てて確かめる。
 * アプリの検証（FormRequest・サービス）を通らない経路でも、最後の砦として DB が守ることの確認。
 * ここではクエリビルダ（DB::table。Rails の ActiveRecord を通さない connection.execute に近い）を使い、モデルのフックを通さない。
 * https://readouble.com/laravel/13.x/ja/migrations.html#foreign-key-constraints
 */
class DomainSchemaTest extends TestCase
{
    public function test_応募のある募集はdbでも削除できない(): void
    {
        $application = Application::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('job_postings')->where('id', $application->job_posting_id)->delete();
    }

    public function test_応募のあるワーカーはdbでも削除できない(): void
    {
        $application = Application::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('workers')->where('id', $application->worker_id)->delete();
    }

    public function test_勤怠のある応募はdbでも削除できない(): void
    {
        $attendance = Attendance::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('applications')->where('id', $attendance->application_id)->delete();
    }

    public function test_業務データのある企業はdbでも削除できない(): void
    {
        Worker::factory()->create();
        // users も restrictOnDelete なので、先に管理者を消してから企業の削除を試す（workers の制約だけを見る）
        DB::table('users')->where('company_id', $this->company->id)->delete();

        $this->expectException(QueryException::class);

        DB::table('companies')->where('id', $this->company->id)->delete();
    }

    public function test_同じワーカーでも別の募集なら応募でき同じ募集でも別のワーカーなら応募できる(): void
    {
        $application = Application::factory()->create();

        Application::factory()->create(['worker_id' => $application->worker_id]);
        Application::factory()->create(['job_posting_id' => $application->job_posting_id]);

        $this->assertSame(3, Application::query()->count());
    }

    public function test_dbの既定値はdocs01の3_1どおり(): void
    {
        $location = Location::factory()->create();
        $now = now();
        $base = ['company_id' => $this->company->id, 'created_at' => $now, 'updated_at' => $now];

        $workerId = DB::table('workers')->insertGetId([...$base, 'name' => '既定', 'email' => 'default@example.com']);
        $postingId = DB::table('job_postings')->insertGetId([
            ...$base, 'location_id' => $location->id, 'job_title' => '既定', 'work_date' => '2026-10-10',
            'start_time' => '09:00', 'end_time' => '17:00', 'capacity' => 1, 'hourly_wage' => 1300,
        ]);
        $applicationId = DB::table('applications')->insertGetId([...$base, 'job_posting_id' => $postingId, 'worker_id' => $workerId]);
        $attendanceId = DB::table('attendances')->insertGetId([
            ...$base, 'application_id' => $applicationId, 'clock_in' => '2026-10-10 09:00:00', 'clock_out' => '2026-10-10 17:00:00',
            'worked_minutes' => 480, 'wage_pay' => 10400, 'total_pay' => 10400,
        ]);

        $this->assertSame(1, (int) DB::table('workers')->where('id', $workerId)->value('is_active'));
        $this->assertSame('open', DB::table('job_postings')->where('id', $postingId)->value('status'));
        $this->assertSame(0, (int) DB::table('job_postings')->where('id', $postingId)->value('transport_allowance'));
        $this->assertSame('applied', DB::table('applications')->where('id', $applicationId)->value('status'));
        $this->assertSame(0, (int) DB::table('attendances')->where('id', $attendanceId)->value('break_minutes'));
    }

    public function test_ワーカーのメールはdbでも必須(): void
    {
        $this->expectException(QueryException::class);

        DB::table('workers')->insert([
            'company_id' => $this->company->id, 'name' => 'メールなし', 'email' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_ワーカーのメールは一意ではない(): void
    {
        Worker::factory()->create(['email' => 'same@example.com']);
        Worker::factory()->create(['email' => 'same@example.com']);

        $this->assertSame(2, Worker::query()->where('email', 'same@example.com')->count());
    }

    public function test_docs01の3_1の索引と一意制約がある(): void
    {
        $expected = [
            'workers' => [['company_id', 'is_active'], ['company_id', 'name']],
            'job_postings' => [['company_id', 'work_date'], ['company_id', 'status'], ['location_id']],
            'applications' => [['job_posting_id', 'status'], ['company_id', 'status']],
            'attendances' => [['company_id', 'clock_in']],
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
        $this->assertContains(['job_posting_id', 'worker_id'], $uniques('applications'));
        $this->assertContains(['application_id'], $uniques('attendances'));
        // メールの一意制約は付けない（docs/01 3.1）
        $this->assertNotContains(['email'], $uniques('workers'));
    }

    public function test_sqliteは外部キー制約が有効(): void
    {
        $this->assertTrue((bool) config('database.connections.sqlite.foreign_key_constraints'));
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    public function test_募集が存在しない拠点を指すことはできない(): void
    {
        $this->expectException(QueryException::class);

        JobPosting::factory()->create(['location_id' => 999999]);
    }
}
