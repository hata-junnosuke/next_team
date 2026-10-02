<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Enums\NotificationStatus;
use App\Enums\ReportExportStatus;
use App\Jobs\BuildLocationCsvChunk;
use App\Jobs\FinalizeReportExport;
use App\Models\Application;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\ReportExportReady;
use App\Services\MonthlyReportService;
use App\Services\NotificationLogService;
use App\Services\ReportExportService;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * F-23 月次集計の非同期出力の補強（tester。P4-05）。ReportExportTest にない受け入れ条件を 1 条件 1 本で足す:
 * 受付が生成を待たないこと（database キューで実際に積んでワーカーで回す）、CSV の中身（同期 CSV との一致・6 種の先頭文字・
 * 確定の勤怠だけ・他社が混ざらない）、拠点の多い月、チャンクの一部失敗と再試行、同じ月の続けての依頼、署名付き URL の境界、
 * download_url と progress、通知の宛先、Storage の後片付け。
 */
class ReportExportCoverageTest extends TestCase
{
    /** docs/01 6.9 の ReportExportResource のキー */
    private const RESOURCE_KEYS = ['id', 'month', 'status', 'progress', 'row_count', 'requested_at', 'completed_at', 'download_url'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function shift(string $date, ?Location $location = null, ?Worker $worker = null): Attendance
    {
        $posting = JobPosting::factory()->onDate($date)->for($location ?? Location::factory())->create();
        $application = Application::factory()->for($posting)->for($worker ?? Worker::factory())->confirmed()->create();

        return Attendance::factory()->for($application)->create();
    }

    private function requestExport(string $month): TestResponse
    {
        return $this->actingAsAdmin()->postJson('/api/report-exports', ['month' => $month]);
    }

    /**
     * 引用符の中の改行・CR も読めるよう fgetcsv で読む（BOM は除く）
     *
     * @return list<list<string>>
     */
    private static function readCsv(string $body): array
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, substr($body, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    private function exportBody(ReportExport $export): string
    {
        return (string) Storage::disk('local')->get((string) $export->fresh()->file_path);
    }

    /**
     * database キューのワーカーを 1 回だけ回す（別プロセスのワーカーと同じく Context を空にしてから）
     */
    private function workOnce(): void
    {
        Context::forget('company_id');
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--sleep' => 0])->assertExitCode(0);
        CurrentCompany::set($this->company->id);
    }

    private function getAsGuest(string $url): TestResponse
    {
        Context::flush();

        return $this->get($url);
    }

    private static function downloadUrl(ReportExport $export, int $minutes = 30): string
    {
        return URL::temporarySignedRoute('report-exports.download', now()->addMinutes($minutes), ['report_export' => $export->id]);
    }

    private function insertBatch(string $id, int $total, int $pending): void
    {
        DB::table('job_batches')->insert([
            'id' => $id, 'name' => 'report-export', 'total_jobs' => $total, 'pending_jobs' => $pending,
            'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => null, 'cancelled_at' => null,
            'created_at' => now()->getTimestamp(), 'finished_at' => null,
        ]);
    }

    // ---- 受付は生成を待たない（F-23「受付だけが即座に返り」）----

    public function test_受付の応答の時点ではcsvも通知も作られていない(): void
    {
        Bus::fake();
        Notification::fake();
        $this->shift('2026-09-10');

        $this->requestExport('2026-09')->assertStatus(202)->assertJsonPath('data.status.value', 'queued');

        $this->assertSame([], Storage::disk('local')->allFiles());
        Notification::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_databaseキューでは受付はprocessingで返りワーカーが回ると進捗が進んで完了する(): void
    {
        config(['queue.default' => 'database']);
        Notification::fake();
        $this->shift('2026-09-10', Location::factory()->create());
        $this->shift('2026-09-11', Location::factory()->create());

        $response = $this->requestExport('2026-09')->assertStatus(202)
            ->assertJsonPath('data.status.value', 'processing')
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.download_url', null);
        $id = $response->json('data.id');
        $this->assertSame([], Storage::disk('local')->allFiles(), '受付の応答はファイルの生成を待たない');
        $this->assertSame(2, DB::table('jobs')->where('queue', 'default')->count());

        $this->workOnce();
        $this->actingAsAdmin()->getJson("/api/report-exports/{$id}")->assertOk()
            ->assertJsonPath('data.status.value', 'processing')
            ->assertJsonPath('data.progress', 50);

        $this->workOnce(); // 2 本目のチャンク → then で FinalizeReportExport が積まれる
        $this->assertSame(1, DB::table('jobs')->count());
        Notification::assertNothingSent();

        $this->workOnce(); // 仕上げ
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->actingAsAdmin()->getJson("/api/report-exports/{$id}")->assertOk()
            ->assertJsonPath('data.status.value', 'completed')
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.row_count', 2);

        $export = ReportExport::query()->findOrFail($id);
        $this->assertSame([$export->file_path], Storage::disk('local')->allFiles(), 'chunks は残らず完成した CSV だけが残る');
        Notification::assertSentToTimes($this->admin, ReportExportReady::class, 1);
    }

    // ---- 同じ月の続けての依頼（docs/01 12 章）----

    public function test_同じ月を続けて2回依頼すると2回目は422で行もバッチも1つ(): void
    {
        Bus::fake();
        $this->shift('2026-09-10');

        $this->requestExport('2026-09')->assertStatus(202);
        $this->requestExport('2026-09')
            ->assertStatus(422)
            ->assertJsonPath('message', '同じ月の出力が生成中です。')
            ->assertJsonStructure(['message', 'errors']);

        $this->assertSame(1, ReportExport::query()->count());
        Bus::assertBatchCount(1);
    }

    public function test_ある月が生成中でも別の月は依頼できる(): void
    {
        Bus::fake();
        ReportExport::factory()->processing()->create(['month' => '2026-09']);

        $this->requestExport('2026-08')->assertStatus(202)->assertJsonPath('data.month', '2026-08');
    }

    public function test_チャンクが失敗して出力がfailedになった月はもう一度依頼すると完了する(): void
    {
        Notification::fake();
        $location = Location::factory()->create();
        $this->shift('2026-09-10', $location);
        $this->partialMock(MonthlyReportService::class, function ($mock) use ($location) {
            $mock->shouldReceive('csvRows')->with('2026-09', $location->id)->andThrow(new RuntimeException('集計に失敗'));
        });
        $this->requestExport('2026-09')->assertStatus(202)->assertJsonPath('data.status.value', 'failed');

        $this->app->forgetInstance(MonthlyReportService::class); // 復旧した
        $this->requestExport('2026-09')->assertStatus(202)->assertJsonPath('data.status.value', 'completed');

        $this->assertSame(['failed', 'completed'], ReportExport::query()->orderBy('id')->pluck('status')->map->value->all());
        Notification::assertSentTimes(ReportExportReady::class, 1);
    }

    // ---- CSV の中身 ----

    public function test_非同期のcsvは同期のcsvと行の集合が一致する(): void
    {
        $tokyo = Location::factory()->create(['name' => '東京倉庫']);
        $osaka = Location::factory()->create(['name' => '大阪倉庫']);
        $sato = Worker::factory()->create(['name' => '佐藤']);
        $suzuki = Worker::factory()->create(['name' => '鈴木, "二郎"']);
        $this->shift('2026-09-01', $osaka, $sato);
        $this->shift('2026-09-15', $tokyo, $suzuki);
        $this->shift('2026-09-15', $osaka, $suzuki);
        $this->shift('2026-09-30', $tokyo, $sato);
        $this->shift('2026-08-31', $tokyo, $sato); // 前月
        $this->shift('2026-10-01', $osaka, $sato); // 翌月

        $sync = $this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-09')->assertOk()->streamedContent();
        $this->requestExport('2026-09')->assertStatus(202)->assertJsonPath('data.status.value', 'completed');
        $async = $this->exportBody(ReportExport::query()->sole());

        $this->assertStringStartsWith("\xEF\xBB\xBF", $async);
        $syncRows = self::readCsv($sync);
        $asyncRows = self::readCsv($async);
        $this->assertSame($syncRows[0], $asyncRows[0], 'ヘッダが同じ');
        $syncBody = array_slice($syncRows, 1);
        $asyncBody = array_slice($asyncRows, 1);
        sort($syncBody);
        sort($asyncBody);
        $this->assertCount(4, $asyncBody);
        $this->assertSame($syncBody, $asyncBody, '並び順（拠点ごと）以外は同期の CSV と同じ行');
        $this->assertSame(4, ReportExport::query()->sole()->row_count);
    }

    public function test_非同期のcsvはbomつきで全行が7列(): void
    {
        $this->shift('2026-09-10');
        $this->shift('2026-09-11');

        $this->requestExport('2026-09')->assertStatus(202);
        $body = $this->exportBody(ReportExport::query()->sole());

        $this->assertSame("\xEF\xBB\xBF", substr($body, 0, 3));
        $rows = self::readCsv($body);
        $this->assertSame(['ワーカー名', '拠点名', '勤務日', '労働分', '賃金', '交通費', '合計'], $rows[0]);
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertCount(7, $row);
        }
    }

    public function test_非同期のcsvは6種の先頭文字のワーカー名と拠点名にクォートが付く(): void
    {
        $prefixes = ['=', '+', '-', '@', "\t", "\r"];
        foreach ($prefixes as $i => $prefix) {
            $this->shift(
                sprintf('2026-09-%02d', $i + 1),
                Location::factory()->create(['name' => "{$prefix}拠点{$i}"]),
                Worker::factory()->create(['name' => "{$prefix}ワーカー{$i}"]),
            );
        }

        $this->requestExport('2026-09')->assertStatus(202);
        $rows = array_slice(self::readCsv($this->exportBody(ReportExport::query()->sole())), 1);

        $this->assertCount(6, $rows);
        $workers = array_column($rows, 0);
        $locations = array_column($rows, 1);
        sort($workers);
        sort($locations);
        $expectedWorkers = array_map(fn (int $i) => "'{$prefixes[$i]}ワーカー{$i}", array_keys($prefixes));
        $expectedLocations = array_map(fn (int $i) => "'{$prefixes[$i]}拠点{$i}", array_keys($prefixes));
        sort($expectedWorkers);
        sort($expectedLocations);
        $this->assertSame($expectedWorkers, $workers);
        $this->assertSame($expectedLocations, $locations);
    }

    public function test_非同期のcsvは確定の応募の勤怠だけを含む(): void
    {
        $location = Location::factory()->create();
        $this->shift('2026-09-10', $location);
        $cancelled = $this->shift('2026-09-11', $location);
        $cancelled->application->transitionTo(ApplicationStatus::Cancelled, ['cancel_reason' => CancelReason::Worker, 'cancelled_at' => now()]);
        // 確定したが勤怠が未入力・応募中のまま
        Application::factory()->for(JobPosting::factory()->onDate('2026-09-12')->for($location))->confirmed()->create();
        Application::factory()->for(JobPosting::factory()->onDate('2026-09-13')->for($location))->create();

        $this->requestExport('2026-09')->assertStatus(202);

        $rows = array_slice(self::readCsv($this->exportBody(ReportExport::query()->sole())), 1);
        $this->assertSame(['2026-09-10'], array_column($rows, 2));
        $this->assertSame(1, ReportExport::query()->sole()->row_count);
    }

    public function test_非同期のcsvに他社の勤怠は混ざらずバッチも自社の拠点だけ(): void
    {
        Bus::fake();
        $this->shift('2026-09-10', Location::factory()->create(['name' => '自社の拠点']));
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, function () {
            $this->shift('2026-09-10', Location::factory()->create(['name' => '他社の拠点']));
            $this->shift('2026-09-11', Location::factory()->create(['name' => '他社の拠点2']));
        });

        $this->requestExport('2026-09')->assertStatus(202);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
    }

    public function test_非同期のcsvの本文に他社の勤怠が含まれない(): void
    {
        $this->shift('2026-09-10', Location::factory()->create(['name' => '自社の拠点']));
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, fn () => $this->shift('2026-09-10', Location::factory()->create(['name' => '他社の拠点'])));

        $this->requestExport('2026-09')->assertStatus(202);

        $rows = array_slice(self::readCsv($this->exportBody(ReportExport::query()->sole())), 1);
        $this->assertSame(['自社の拠点'], array_column($rows, 1));
    }

    public function test_他社の拠点idを渡したチャンクは空になる(): void
    {
        $export = ReportExport::factory()->processing()->create(['month' => '2026-09']);
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => $this->shift('2026-09-10')->application->jobPosting->location_id);

        (new BuildLocationCsvChunk($export->id, $theirs))->handle(app(ReportExportService::class));

        $path = "exports/{$this->company->id}/{$export->id}/chunks/{$theirs}.csv";
        Storage::disk('local')->assertExists($path);
        $this->assertSame('', Storage::disk('local')->get($path));
    }

    // ---- 拠点の多い月 ----

    public function test_拠点が12ある月は12本のチャンクを拠点idの数値順に連結する(): void
    {
        $names = [];
        for ($i = 1; $i <= 12; $i++) {
            $location = Location::factory()->create(['name' => sprintf('拠点%02d', $i)]);
            $names[$location->id] = $location->name;
            // 勤務日は拠点の逆順（勤務日順ではなく拠点 ID 順に並ぶことを確かめる）
            $this->shift(sprintf('2026-09-%02d', 13 - $i), $location);
        }
        ksort($names, SORT_NUMERIC);

        $this->requestExport('2026-09')->assertStatus(202)->assertJsonPath('data.row_count', 12);

        $export = ReportExport::query()->sole();
        $this->assertDatabaseHas('job_batches', ['id' => $export->batch_id, 'total_jobs' => 12, 'pending_jobs' => 0]);
        $rows = array_slice(self::readCsv($this->exportBody($export)), 1);
        $this->assertSame(array_values($names), array_column($rows, 1));
    }

    // ---- チャンクの失敗・再試行・後片付け ----

    public function test_チャンクを再試行して上書きしても行は二重にならない(): void
    {
        Notification::fake();
        $location = Location::factory()->create();
        $this->shift('2026-09-10', $location);
        $export = ReportExport::factory()->processing()->create(['month' => '2026-09']);
        $chunk = new BuildLocationCsvChunk($export->id, $location->id);

        $chunk->handle(app(ReportExportService::class));
        $chunk->handle(app(ReportExportService::class));
        (new FinalizeReportExport($export->id))->handle(app(ReportExportService::class), app(NotificationLogService::class));

        $this->assertSame(1, $export->fresh()->row_count);
        $this->assertCount(2, self::readCsv($this->exportBody($export)));
    }

    public function test_チャンクのジョブが打ち切られるとfailedになり書きかけのファイルが消える(): void
    {
        $location = Location::factory()->create();
        $this->shift('2026-09-10', $location);
        $export = ReportExport::factory()->processing()->create(['month' => '2026-09']);
        (new BuildLocationCsvChunk($export->id, $location->id))->handle(app(ReportExportService::class));
        $this->assertNotSame([], Storage::disk('local')->allFiles("exports/{$this->company->id}/{$export->id}"));

        (new BuildLocationCsvChunk($export->id, $location->id))->failed(new RuntimeException('打ち切り'));

        $this->assertSame(ReportExportStatus::Failed, $export->fresh()->status);
        $this->assertNotNull($export->fresh()->failed_at);
        $this->assertSame([], Storage::disk('local')->allFiles("exports/{$this->company->id}/{$export->id}"));
    }

    public function test_失敗した出力の後片付けは他の出力のファイルを消さない(): void
    {
        $done = ReportExport::factory()->completed()->create(['month' => '2026-08']);
        Storage::disk('local')->put($done->file_path, 'csv');
        $location = Location::factory()->create();
        $this->shift('2026-09-10', $location);
        $export = ReportExport::factory()->processing()->create(['month' => '2026-09']);
        (new BuildLocationCsvChunk($export->id, $location->id))->handle(app(ReportExportService::class));

        app(ReportExportService::class)->markFailed($export->id);

        Storage::disk('local')->assertExists($done->file_path);
        $this->assertSame([$done->file_path], Storage::disk('local')->allFiles());
    }

    public function test_完了した出力にmarkfailedが遅れて呼ばれてもcompletedのままでファイルも残る(): void
    {
        $export = ReportExport::factory()->completed()->create(['month' => '2026-09']);
        Storage::disk('local')->put($export->file_path, 'csv');

        app(ReportExportService::class)->markFailed($export->id);

        $this->assertSame(ReportExportStatus::Completed, $export->fresh()->status);
        Storage::disk('local')->assertExists($export->file_path);
    }

    // ---- 署名付き URL ----

    public function test_29分後のurlはまだダウンロードできる(): void
    {
        $export = ReportExport::factory()->completed()->create();
        Storage::disk('local')->put($export->file_path, 'csv');
        $url = self::downloadUrl($export);

        $this->travel(29)->minutes();

        $this->getAsGuest($url)->assertOk();
    }

    public function test_queuedとfailedの出力の署名付きurlは404(): void
    {
        $queued = ReportExport::factory()->create();
        $failed = ReportExport::factory()->failed()->create(['month' => '2026-01']);

        $this->getAsGuest(self::downloadUrl($queued))->assertStatus(404);
        $this->getAsGuest(self::downloadUrl($failed))->assertStatus(404);
    }

    public function test_completedでもファイルがなければ署名付きurlは404(): void
    {
        $export = ReportExport::factory()->completed()->create();

        $this->getAsGuest(self::downloadUrl($export))->assertStatus(404)->assertJsonStructure(['message', 'errors']);
    }

    public function test_存在しない出力idの署名付きurlは404(): void
    {
        $url = URL::temporarySignedRoute('report-exports.download', now()->addMinutes(30), ['report_export' => 999999]);

        $this->getAsGuest($url)->assertStatus(404);
    }

    public function test_ダウンロードはfile_pathのファイルだけを返し他の出力のファイルは返さない(): void
    {
        $mine = ReportExport::factory()->completed()->create(['month' => '2026-09']);
        $another = ReportExport::factory()->completed()->create(['month' => '2026-08']);
        Storage::disk('local')->put($mine->file_path, 'mine');
        Storage::disk('local')->put($another->file_path, 'another');

        $this->assertSame('mine', $this->getAsGuest(self::downloadUrl($mine))->assertOk()->streamedContent());
    }

    // ---- 応答の形・download_url・progress ----

    public function test_受付と一覧と詳細の応答の形はdocs01の6_9どおり(): void
    {
        Bus::fake();
        $created = $this->requestExport('2026-09')->assertStatus(202)
            ->assertJsonStructure(['data' => self::RESOURCE_KEYS + ['status' => ['value', 'label']]]);
        $this->assertSame(self::RESOURCE_KEYS, array_keys($created->json('data')));
        $this->assertSame(['value', 'label'], array_keys($created->json('data.status')));
        $id = $created->json('data.id');

        $this->assertSame(self::RESOURCE_KEYS, array_keys($this->actingAsAdmin()->getJson("/api/report-exports/{$id}")->assertOk()->json('data')));

        $index = $this->actingAsAdmin()->getJson('/api/report-exports')->assertOk();
        $this->assertSame(['data'], array_keys($index->json()), '一覧はページなし（meta / links なし）');
        $this->assertSame(self::RESOURCE_KEYS, array_keys($index->json('data.0')));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+09:00$/', $index->json('data.0.requested_at'));
    }

    public function test_未ログインは詳細も401(): void
    {
        $export = ReportExport::factory()->completed()->create();

        $this->getJson("/api/report-exports/{$export->id}")->assertStatus(401);
    }

    public function test_processingの出力にはdownload_urlが入らず一覧のcompletedにだけ入る(): void
    {
        $processing = ReportExport::factory()->processing()->create(['month' => '2026-09']);
        $completed = ReportExport::factory()->completed()->create(['month' => '2026-08']);

        $this->actingAsAdmin()->getJson("/api/report-exports/{$processing->id}")->assertOk()
            ->assertJsonPath('data.download_url', null);

        $rows = collect($this->actingAsAdmin()->getJson('/api/report-exports')->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($rows[$processing->id]['download_url']);
        $this->assertIsString($rows[$completed->id]['download_url']);
        $this->getAsGuest($rows[$completed->id]['download_url'])->assertStatus(404); // ファイルを置いていないので 404（署名は通る）
    }

    public function test_一覧のdownload_urlでそのままダウンロードできる(): void
    {
        $this->shift('2026-09-10');
        $this->requestExport('2026-09')->assertStatus(202);
        $url = $this->actingAsAdmin()->getJson('/api/report-exports')->assertOk()->json('data.0.download_url');

        $this->app['auth']->forgetGuards();
        $body = $this->getAsGuest($url)->assertOk()->streamedContent();

        $this->assertCount(2, self::readCsv($body));
    }

    public function test_progressはqueuedで0でcompletedは100でfailedはバッチの進捗(): void
    {
        $queued = ReportExport::factory()->create(['month' => '2026-09']);
        $completed = ReportExport::factory()->completed()->create(['month' => '2026-08']);
        $failed = ReportExport::factory()->failed()->create(['month' => '2026-07']);
        $this->insertBatch($failed->batch_id, 3, 2);

        $this->actingAsAdmin()->getJson("/api/report-exports/{$queued->id}")->assertJsonPath('data.progress', 0);
        $this->actingAsAdmin()->getJson("/api/report-exports/{$completed->id}")->assertJsonPath('data.progress', 100);
        $this->actingAsAdmin()->getJson("/api/report-exports/{$failed->id}")->assertJsonPath('data.progress', 33);
    }

    public function test_processingでjob_batchesの行がなければprogressは0(): void
    {
        $export = ReportExport::factory()->processing()->create();

        $this->actingAsAdmin()->getJson("/api/report-exports/{$export->id}")->assertOk()->assertJsonPath('data.progress', 0);
    }

    // ---- 通知の宛先と回数 ----

    public function test_出力完了通知は依頼者だけに届き同じ企業の他の管理者や他社の管理者には届かない(): void
    {
        Notification::fake();
        $colleague = User::factory()->for($this->company)->create();
        $outsider = User::factory()->for(Company::factory())->create();
        $this->shift('2026-09-10');

        $this->requestExport('2026-09')->assertStatus(202);

        Notification::assertSentToTimes($this->admin, ReportExportReady::class, 1);
        Notification::assertNotSentTo($colleague, ReportExportReady::class);
        Notification::assertNotSentTo($outsider, ReportExportReady::class);
        Notification::assertSentTimes(ReportExportReady::class, 1);
    }

    public function test_依頼1件につき出力完了の記録とアプリ内通知は1件ずつ(): void
    {
        $this->shift('2026-09-10', Location::factory()->create());
        $this->shift('2026-09-11', Location::factory()->create());
        $this->shift('2026-09-12', Location::factory()->create());

        $this->requestExport('2026-09')->assertStatus(202);

        $this->assertSame(1, DB::table('notifications')->count(), '拠点が 3 つでも通知は 1 件');
        $this->assertSame(1, NotificationLog::query()->count());
        $this->assertSame(NotificationStatus::Sent, NotificationLog::query()->sole()->status);
    }

    public function test_同じ月を完了後に依頼し直すと出力ごとに1通ずつ届く(): void
    {
        Notification::fake();
        $this->shift('2026-09-10');

        $this->requestExport('2026-09')->assertStatus(202)->assertJsonPath('data.status.value', 'completed');
        $this->requestExport('2026-09')->assertStatus(202)->assertJsonPath('data.status.value', 'completed');

        Notification::assertSentToTimes($this->admin, ReportExportReady::class, 2);
        $ids = ReportExport::query()->orderBy('id')->pluck('id')->all();
        $this->assertSame(
            array_map(fn (int $id) => "export_ready:report_export:{$id}", $ids),
            NotificationLog::query()->orderBy('id')->pluck('dedupe_key')->all(),
        );
    }
}
