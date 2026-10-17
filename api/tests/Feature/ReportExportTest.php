<?php

namespace Tests\Feature;

use App\Enums\NotificationKind;
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
use Illuminate\Bus\PendingBatch;
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
 * F-23 月次集計の非同期出力（docs/01 6.9・8 章）。
 *
 * phpunit.xml は QUEUE_CONNECTION=sync なので、Bus::batch()->dispatch() の中でチャンク・then・FinalizeReportExport まで
 * その場で走り、POST の応答の時点で completed になっている。「積まれたか」は Bus::fake() で、
 * 「実行したらどうなるか」は sync のままか、ジョブの handle() を直接呼んで確かめる（両方を 1 本に混ぜない）。
 */
class ReportExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * 確定済みの応募に勤怠を 1 件作る（既定の募集なら 420 分・賃金 9,100 円・合計 9,600 円）
     */
    private function shift(string $date, Location $location, ?Worker $worker = null): Attendance
    {
        $posting = JobPosting::factory()->onDate($date)->for($location)->create();
        $application = Application::factory()
            ->for($posting)
            ->for($worker ?? Worker::factory())
            ->confirmed()
            ->create();

        return Attendance::factory()->for($application)->create();
    }

    private function requestExport(string $month): TestResponse
    {
        return $this->actingAsAdmin()->postJson('/api/report-exports', ['month' => $month]);
    }

    /**
     * BOM を除いて CSV を行の配列にする
     *
     * @return list<list<string>>
     */
    private static function parseCsv(string $body): array
    {
        $lines = preg_split('/\r\n|\n/', rtrim(substr($body, 3), "\r\n"));

        return array_map(fn (string $line) => str_getcsv($line, escape: ''), $lines);
    }

    private static function downloadUrl(ReportExport $export, int $minutes = 30): string
    {
        return URL::temporarySignedRoute('report-exports.download', now()->addMinutes($minutes), ['report_export' => $export->id]);
    }

    /**
     * ダウンロードには企業もログインもない（メールのリンクやブラウザの直接遷移）。Context とログインを外して叩く
     */
    private function getAsGuest(string $url): TestResponse
    {
        Context::flush();

        return $this->get($url);
    }

    // ---- 受付（202）----

    public function test_依頼すると202が返りqueuedの行と拠点数分のバッチが作られる(): void
    {
        Bus::fake();
        $this->shift('2026-09-10', Location::factory()->create());
        $this->shift('2026-09-11', Location::factory()->create());
        $this->shift('2026-10-01', Location::factory()->create()); // 翌月の拠点はバッチに入らない

        $response = $this->requestExport('2026-09');

        $response->assertStatus(202)
            ->assertJsonPath('data.month', '2026-09')
            ->assertJsonPath('data.status', ['value' => 'queued', 'label' => '受付'])
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.row_count', null)
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.download_url', null);

        $export = ReportExport::query()->sole();
        $this->assertSame(ReportExportStatus::Queued, $export->status);
        $this->assertSame($this->admin->id, $export->user_id);
        $this->assertSame($response->json('data.id'), $export->id);

        Bus::assertBatched(fn (PendingBatch $batch) => $batch->name === "report-export:{$export->id}"
            && $batch->queue() === 'default'
            && $batch->jobs->count() === 2
            && $batch->jobs->every(fn ($job) => $job instanceof BuildLocationCsvChunk && $job->reportExportId === $export->id));
        Bus::assertBatchCount(1);
    }

    public function test_monthがないか形式が違うと422(): void
    {
        $this->actingAsAdmin()->postJson('/api/report-exports', [])
            ->assertStatus(422)->assertJsonValidationErrors('month');
        $this->requestExport('2026-9')->assertStatus(422)->assertJsonValidationErrors('month');

        $this->assertSame(0, ReportExport::query()->count());
    }

    public function test_未ログインは401(): void
    {
        $this->postJson('/api/report-exports', ['month' => '2026-09'])->assertStatus(401);
        $this->getJson('/api/report-exports')->assertStatus(401);
    }

    public function test_同じ月が生成中なら422(): void
    {
        ReportExport::factory()->create(['month' => '2026-09']);
        ReportExport::factory()->processing()->create(['month' => '2026-08']);

        $this->requestExport('2026-09')
            ->assertStatus(422)
            ->assertJsonPath('message', '同じ月の出力が生成中です。');
        $this->requestExport('2026-08')
            ->assertStatus(422)
            ->assertJsonPath('message', '同じ月の出力が生成中です。');

        $this->assertSame(2, ReportExport::query()->count());
    }

    public function test_締め切りより前のprocessingがあっても202になり古い行がfailedになる(): void
    {
        Bus::fake();
        $this->travelTo('2026-10-02 10:00:00');
        $stuck = ReportExport::factory()->processing()->create(['month' => '2026-09']);
        $stuckQueued = ReportExport::factory()->create(['month' => '2026-09']);
        $otherMonth = ReportExport::factory()->processing()->create(['month' => '2026-08']);
        Storage::disk('local')->put("exports/{$this->company->id}/{$stuck->id}/chunks/1.csv", '途中');

        $this->travel(31)->minutes(); // config('myteam.report_export_stale_minutes') の既定 30 分を過ぎた

        $this->requestExport('2026-09')->assertStatus(202)->assertJsonPath('data.status.value', 'queued');

        $this->assertSame(ReportExportStatus::Failed, $stuck->fresh()->status);
        $this->assertNotNull($stuck->fresh()->failed_at);
        $this->assertSame(ReportExportStatus::Failed, $stuckQueued->fresh()->status);
        $this->assertSame(ReportExportStatus::Processing, $otherMonth->fresh()->status, '別の月の生成中には触らない');
        $this->assertSame([], Storage::disk('local')->allFiles("exports/{$this->company->id}/{$stuck->id}"));
        $this->assertSame(4, ReportExport::query()->count());
    }

    public function test_failedにした古い出力のバッチがキャンセルされる(): void
    {
        // Bus::fake() にすると findBatch() が本物のバッチを読まないので、sync のまま（2026-09 は拠点 0 件で仕上げだけが走る）
        Notification::fake();
        $this->travelTo('2026-10-02 10:00:00');
        $stuck = ReportExport::factory()->processing()->create(['month' => '2026-09']);
        DB::table('job_batches')->insert([
            'id' => $stuck->batch_id, 'name' => "report-export:{$stuck->id}", 'total_jobs' => 2, 'pending_jobs' => 2,
            'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => serialize([]), 'cancelled_at' => null,
            'created_at' => now()->getTimestamp(), 'finished_at' => null,
        ]);
        $this->travel(31)->minutes();

        $this->requestExport('2026-09')->assertStatus(202);

        $this->assertSame(ReportExportStatus::Failed, $stuck->fresh()->status);
        $this->assertNotNull(DB::table('job_batches')->where('id', $stuck->batch_id)->value('cancelled_at'));
    }

    public function test_締め切り前の生成中は422のままで失敗にしない(): void
    {
        $this->travelTo('2026-10-02 10:00:00');
        $running = ReportExport::factory()->processing()->create(['month' => '2026-09']);
        $this->travel(29)->minutes();

        $this->requestExport('2026-09')->assertStatus(422);

        $this->assertSame(ReportExportStatus::Processing, $running->fresh()->status);
    }

    public function test_同じ月でも完了か失敗の後なら新しく依頼できる(): void
    {
        Bus::fake();
        ReportExport::factory()->completed()->create(['month' => '2026-09']);
        ReportExport::factory()->failed()->create(['month' => '2026-08']);

        $this->requestExport('2026-09')->assertStatus(202);
        $this->requestExport('2026-08')->assertStatus(202);
    }

    public function test_他社の同じ月が生成中でも依頼できる(): void
    {
        Bus::fake();
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, fn () => ReportExport::factory()->create(['month' => '2026-09']));

        $this->requestExport('2026-09')->assertStatus(202);
    }

    // ---- 実行（sync キューなので POST の中で最後まで走る）----

    public function test_バッチが完了するとcompletedになりcsvが保存され管理者に出力完了通知が積まれる(): void
    {
        Notification::fake();
        $tokyo = Location::factory()->create(['name' => '東京倉庫']);
        $osaka = Location::factory()->create(['name' => '=大阪倉庫']);
        $worker = Worker::factory()->create(['name' => '山田 太郎']);
        $this->shift('2026-09-10', $tokyo, $worker);
        $this->shift('2026-09-12', $tokyo, $worker);
        $this->shift('2026-09-11', $osaka, $worker);

        $response = $this->requestExport('2026-09')->assertStatus(202);

        $export = ReportExport::query()->sole();
        $this->assertSame(ReportExportStatus::Completed, $export->status);
        $this->assertSame(3, $export->row_count);
        $this->assertNotNull($export->completed_at);
        $this->assertNotNull($export->batch_id, '生成中（processing）を通ったなら batch_id が残る');
        $this->assertDatabaseHas('job_batches', ['id' => $export->batch_id, 'total_jobs' => 2, 'pending_jobs' => 0, 'failed_jobs' => 0]);
        $this->assertSame("exports/{$this->company->id}/{$export->id}/monthly-report-2026-09.csv", $export->file_path);
        $response->assertJsonPath('data.status.value', 'completed')->assertJsonPath('data.row_count', 3);

        Storage::disk('local')->assertExists($export->file_path);
        $body = Storage::disk('local')->get($export->file_path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $rows = self::parseCsv($body);
        $this->assertSame(MonthlyReportService::CSV_HEADER, $rows[0]);
        // 拠点ごとのチャンクを拠点 ID の順に連結する（拠点の中は勤務日の順）
        $this->assertSame([
            ['山田 太郎', '東京倉庫', '2026-09-10', '420', '9100', '500', '9600'],
            ['山田 太郎', '東京倉庫', '2026-09-12', '420', '9100', '500', '9600'],
            ['山田 太郎', "'=大阪倉庫", '2026-09-11', '420', '9100', '500', '9600'],
        ], array_slice($rows, 1));
        // 途中のチャンクは消す
        $this->assertSame([], Storage::disk('local')->allFiles("exports/{$this->company->id}/{$export->id}/chunks"));

        Notification::assertSentTo($this->admin, ReportExportReady::class,
            fn (ReportExportReady $n, array $channels) => $n->reportExportId === $export->id && $channels === ['mail', 'database']);
        Notification::assertSentTimes(ReportExportReady::class, 1);
        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationKind::ExportReady, $log->kind);
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertSame("export_ready:report_export:{$export->id}", $log->dedupe_key);
        $this->assertSame(['user', $this->admin->id], [$log->recipient_type, $log->recipient_id]);
    }

    public function test_拠点が0件の月はバッチを作らずヘッダだけのcsvでcompletedになる(): void
    {
        Notification::fake();
        $this->shift('2026-10-01', Location::factory()->create()); // 別の月

        $this->requestExport('2026-09')
            ->assertStatus(202)
            ->assertJsonPath('data.status.value', 'completed')
            ->assertJsonPath('data.row_count', 0)
            ->assertJsonPath('data.progress', 100);

        $export = ReportExport::query()->sole();
        $this->assertSame(ReportExportStatus::Completed, $export->status);
        $this->assertSame(0, $export->row_count);
        $this->assertNull($export->batch_id);
        $this->assertSame(0, DB::table('job_batches')->count());
        $this->assertSame("\xEF\xBB\xBF".implode(',', MonthlyReportService::CSV_HEADER)."\n", Storage::disk('local')->get($export->file_path));
        Notification::assertSentTo($this->admin, ReportExportReady::class);
    }

    public function test_拠点が0件の月は受付時にファイナライズのジョブだけが積まれる(): void
    {
        Bus::fake();

        $this->requestExport('2026-09')->assertStatus(202);

        $export = ReportExport::query()->sole();
        Bus::assertNothingBatched();
        Bus::assertDispatched(FinalizeReportExport::class, fn (FinalizeReportExport $job) => $job->reportExportId === $export->id && $job->queue === 'default');
    }

    public function test_バッチのジョブが失敗するとfailedになり通知は送らない(): void
    {
        Notification::fake();
        $location = Location::factory()->create();
        $this->shift('2026-09-10', $location);
        $this->shift('2026-09-11', Location::factory()->create());
        $this->partialMock(MonthlyReportService::class, function ($mock) use ($location) {
            $mock->shouldReceive('csvRows')->with('2026-09', $location->id)->andThrow(new RuntimeException('集計に失敗'));
        });

        $this->requestExport('2026-09')
            ->assertStatus(202)
            ->assertJsonPath('data.status.value', 'failed')
            ->assertJsonPath('data.download_url', null);

        $export = ReportExport::query()->sole();
        $this->assertSame(ReportExportStatus::Failed, $export->status);
        $this->assertNotNull($export->failed_at);
        $this->assertNull($export->file_path);
        $this->assertSame([], Storage::disk('local')->allFiles("exports/{$this->company->id}/{$export->id}"));
        Notification::assertNothingSent();
    }

    public function test_redisと同じ順序でbeforeでprocessingになりcatchでfailedになる(): void
    {
        // Redis のキューでは dispatch() はすぐ戻り、チャンクと then / catch は後でワーカーが動かす。
        // Bus::fake() で記録したバッチのコールバックを、ワーカーと同じ順に手で呼んで状態の移り方を確かめる
        Bus::fake();
        $this->shift('2026-09-10', Location::factory()->create());
        $this->requestExport('2026-09')->assertStatus(202);
        $export = ReportExport::query()->sole();

        /** @var PendingBatch $pending */
        $pending = Bus::batched(fn () => true)->sole();
        [, $batch] = (new BuildLocationCsvChunk($export->id, 1))->withFakeBatch(id: 'batch-123');

        foreach ($pending->beforeCallbacks() as $callback) {
            $callback($batch);
        }
        $this->assertSame(ReportExportStatus::Processing, $export->fresh()->status);
        $this->assertSame('batch-123', $export->fresh()->batch_id);

        foreach ($pending->catchCallbacks() as $callback) {
            $callback($batch, new RuntimeException('チャンクの失敗'));
        }
        $this->assertSame(ReportExportStatus::Failed, $export->fresh()->status);
        $this->assertNotNull($export->fresh()->failed_at);
    }

    public function test_thenでファイナライズのジョブが積まれる(): void
    {
        Bus::fake();
        $this->shift('2026-09-10', Location::factory()->create());
        $this->requestExport('2026-09')->assertStatus(202);
        $export = ReportExport::query()->sole();

        /** @var PendingBatch $pending */
        $pending = Bus::batched(fn () => true)->sole();
        [, $batch] = (new BuildLocationCsvChunk($export->id, 1))->withFakeBatch();
        Bus::assertNotDispatched(FinalizeReportExport::class);

        foreach ($pending->thenCallbacks() as $callback) {
            $callback($batch);
        }

        Bus::assertDispatched(FinalizeReportExport::class, fn (FinalizeReportExport $job) => $job->reportExportId === $export->id);
    }

    public function test_キャンセルされたバッチのチャンクは何も書かない(): void
    {
        $export = ReportExport::factory()->processing()->create(['month' => '2026-09']);
        $location = Location::factory()->create();
        $this->shift('2026-09-10', $location);
        [$job, $batch] = (new BuildLocationCsvChunk($export->id, $location->id))->withFakeBatch();
        $batch->cancel();

        $job->handle(app(ReportExportService::class));

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_書いている間に出力が失敗になったチャンクは自分のファイルを消す(): void
    {
        $export = ReportExport::factory()->processing()->create(['month' => '2026-09']);
        $location = Location::factory()->create();
        $this->shift('2026-09-10', $location);
        // 行を読んでいる間に、別のチャンクの失敗で出力が failed になった
        $this->partialMock(MonthlyReportService::class, function ($mock) use ($export) {
            $mock->shouldReceive('csvRows')->andReturnUsing(function () use ($export) {
                app(ReportExportService::class)->markFailed($export->id);

                return [['a', 'b', '2026-09-10', 1, 1, 0, 1]];
            });
        });

        app(ReportExportService::class)->buildChunk($export->id, $location->id);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_生成中の確認を通った後にfailedになった出力の仕上げはファイルを残さず完了にしない(): void
    {
        Notification::fake();
        $export = ReportExport::factory()->processing()->create(['month' => '2026-09']);
        $chunk = "exports/{$this->company->id}/{$export->id}/chunks/1.csv";
        Storage::disk('local')->put($chunk, "a,b,2026-09-10,1,1,0,1\n");
        // finalize() が出力を読んで「生成中」と確かめた直後に、別の経路（チャンクの failed()・締め切り）で failed になった。
        // 最初の report_exports の SELECT の後に、行を直接 failed に書き換える
        $flipped = false;
        DB::listen(function ($query) use (&$flipped, $export) {
            if (! $flipped && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"report_exports"')) {
                $flipped = true;
                DB::table('report_exports')->where('id', $export->id)
                    ->update(['status' => ReportExportStatus::Failed->value, 'failed_at' => now()]);
            }
        });

        (new FinalizeReportExport($export->id))->handle(app(ReportExportService::class), app(NotificationLogService::class));

        $this->assertSame(ReportExportStatus::Failed, $export->fresh()->status);
        $this->assertNull($export->fresh()->file_path);
        $this->assertTrue($flipped);
        $this->assertSame([], Storage::disk('local')->allFiles("exports/{$this->company->id}/{$export->id}"));
        Notification::assertNothingSent();
    }

    public function test_出力の完了後に通知の送信が打ち切られると通知の記録がfailedで残る(): void
    {
        // claim 済みの行がある（送信で例外が 3 回）
        $export = ReportExport::factory()->completed()->create(['user_id' => $this->admin->id]);
        app(NotificationLogService::class)->claim(NotificationKind::ExportReady, $this->admin, $export);

        (new FinalizeReportExport($export->id))->failed(new RuntimeException('SMTP に繋がらない'));

        $this->assertSame(ReportExportStatus::Completed, $export->fresh()->status, '出力は完了のまま');
        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertSame('RuntimeException: 処理に失敗しました。', $log->error);

        // 行がない（claim の前に打ち切られた）なら recordFailure で failed の行を作る
        $another = ReportExport::factory()->completed()->create(['user_id' => $this->admin->id]);
        (new FinalizeReportExport($another->id))->failed(new RuntimeException('打ち切り'));

        $created = NotificationLog::query()->where('dedupe_key', "export_ready:report_export:{$another->id}")->sole();
        $this->assertSame(NotificationStatus::Failed, $created->status);
        $this->assertSame(['user', $this->admin->id], [$created->recipient_type, $created->recipient_id]);
    }

    public function test_ファイナライズのジョブが打ち切られるとfailedになる(): void
    {
        $export = ReportExport::factory()->processing()->create(['month' => '2026-09']);

        (new FinalizeReportExport($export->id))->failed(new RuntimeException('連結に失敗'));

        $this->assertSame(ReportExportStatus::Failed, $export->fresh()->status);
    }

    public function test_チャンクの失敗を記録した後のファイナライズは完了にしない(): void
    {
        Notification::fake();
        $export = ReportExport::factory()->failed()->create(['month' => '2026-09']);

        (new FinalizeReportExport($export->id))->handle(app(ReportExportService::class), app(NotificationLogService::class));

        $this->assertSame(ReportExportStatus::Failed, $export->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_ジョブの設定はdefaultキューで再試行と失敗の扱いを持つ(): void
    {
        $chunk = new BuildLocationCsvChunk(1, 2);
        $finalize = new FinalizeReportExport(1);

        $this->assertSame(3, $chunk->tries);
        $this->assertSame([10, 60], $chunk->backoff());
        $this->assertSame(3, $finalize->tries);
        $this->assertSame([10, 60], $finalize->backoff());
        $this->assertSame('default', $finalize->queue);
        $this->assertSame(50, $finalize->timeout);
        $lock = $finalize->middleware()[0];
        $this->assertSame(60, $lock->expiresAfter);
        $this->assertNull($lock->releaseAfter, 'dontRelease: 重なった 2 本目は戻さない');
    }

    // ---- 一覧・詳細 ----

    public function test_一覧は新しい順に最大20件(): void
    {
        $old = ReportExport::factory()->completed()->create(['month' => '2026-01', 'created_at' => now()->subDays(30)]);
        ReportExport::factory()->count(20)->completed()->sequence(fn ($s) => ['month' => sprintf('2025-%02d', ($s->index % 12) + 1)])->create();
        $newest = ReportExport::factory()->create(['month' => '2026-09', 'created_at' => now()->addMinute()]);

        $response = $this->actingAsAdmin()->getJson('/api/report-exports')->assertOk();

        $response->assertJsonCount(20, 'data');
        $this->assertSame($newest->id, $response->json('data.0.id'));
        $this->assertNotContains($old->id, array_column($response->json('data'), 'id'));
    }

    public function test_completedの詳細にはdownload_urlが入りqueuedには入らない(): void
    {
        $completed = ReportExport::factory()->completed()->create(['month' => '2026-09']);
        $queued = ReportExport::factory()->create(['month' => '2026-08']);
        $failed = ReportExport::factory()->failed()->create(['month' => '2026-07']);

        $response = $this->actingAsAdmin()->getJson("/api/report-exports/{$completed->id}")->assertOk();
        $response->assertJsonPath('data.id', $completed->id)
            ->assertJsonPath('data.status', ['value' => 'completed', 'label' => '完了'])
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.row_count', 3)
            ->assertJsonPath('data.requested_at', $completed->created_at->toIso8601String())
            ->assertJsonPath('data.completed_at', $completed->completed_at->toIso8601String());
        $url = $response->json('data.download_url');
        $this->assertIsString($url);
        $this->assertStringContainsString("/api/report-exports/{$completed->id}/download?expires=", $url);
        $this->assertStringContainsString('signature=', $url);

        $this->actingAsAdmin()->getJson("/api/report-exports/{$queued->id}")->assertOk()
            ->assertJsonPath('data.status.value', 'queued')
            ->assertJsonPath('data.download_url', null);
        $this->actingAsAdmin()->getJson("/api/report-exports/{$failed->id}")->assertOk()
            ->assertJsonPath('data.download_url', null);
    }

    public function test_download_urlは取得のたびに30分の期限で作り直す(): void
    {
        $this->travelTo('2026-10-02 10:00:00');
        $export = ReportExport::factory()->completed()->create();

        $first = $this->actingAsAdmin()->getJson("/api/report-exports/{$export->id}")->json('data.download_url');
        $this->travel(5)->minutes();
        $second = $this->actingAsAdmin()->getJson("/api/report-exports/{$export->id}")->json('data.download_url');

        parse_str((string) parse_url($first, PHP_URL_QUERY), $q1);
        parse_str((string) parse_url($second, PHP_URL_QUERY), $q2);
        $this->assertSame(now()->subMinutes(5)->addMinutes(30)->getTimestamp(), (int) $q1['expires']);
        $this->assertSame(now()->addMinutes(30)->getTimestamp(), (int) $q2['expires']);
        $this->assertNotSame($first, $second);
    }

    public function test_詳細のprogressはバッチの進捗(): void
    {
        $export = ReportExport::factory()->processing()->create();
        DB::table('job_batches')->insert([
            'id' => $export->batch_id, 'name' => "report-export:{$export->id}", 'total_jobs' => 4, 'pending_jobs' => 3,
            'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => null, 'cancelled_at' => null,
            'created_at' => now()->getTimestamp(), 'finished_at' => null,
        ]);

        $this->actingAsAdmin()->getJson("/api/report-exports/{$export->id}")->assertOk()
            ->assertJsonPath('data.status.value', 'processing')
            ->assertJsonPath('data.progress', 25);
        $this->actingAsAdmin()->getJson('/api/report-exports')->assertOk()
            ->assertJsonPath('data.0.progress', 25);
    }

    public function test_他社の出力は404(): void
    {
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, fn () => ReportExport::factory()->completed()->create());

        $this->actingAsAdmin()->getJson("/api/report-exports/{$theirs->id}")->assertStatus(404);
        $this->actingAsAdmin()->getJson('/api/report-exports')->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- ダウンロード（署名付きURL）----

    public function test_署名付きurlでcsvをダウンロードできる(): void
    {
        Notification::fake();
        $this->shift('2026-09-10', Location::factory()->create(['name' => '東京倉庫']));
        $this->requestExport('2026-09');
        $export = ReportExport::query()->sole();
        $url = $this->actingAsAdmin()->getJson("/api/report-exports/{$export->id}")->json('data.download_url');

        // ログアウトして企業もない状態（メールのリンクから開いた）
        $this->app['auth']->forgetGuards();
        $response = $this->getAsGuest($url);

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('monthly-report-2026-09.csv', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $body = $response->streamedContent();
        $this->assertSame(Storage::disk('local')->get($export->file_path), $body);
        $this->assertSame('東京倉庫', self::parseCsv($body)[1][1]);
    }

    public function test_同じ月なら同期csvと非同期csvのダウンロード名が同じ(): void
    {
        // issue #1: F-18（同期）と F-23（非同期）で利用者の手元に残るファイル名をそろえる（docs/01 6 章）
        Notification::fake();
        $this->shift('2026-09-10', Location::factory()->create());
        $this->requestExport('2026-09');
        $export = ReportExport::query()->sole();
        $url = $this->actingAsAdmin()->getJson("/api/report-exports/{$export->id}")->json('data.download_url');

        $sync = $this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-09');
        $this->app['auth']->forgetGuards();
        $async = $this->getAsGuest($url);

        $sync->assertOk();
        $async->assertOk();
        $this->assertSame('monthly-report-2026-09.csv', self::dispositionFilename($sync));
        $this->assertSame(self::dispositionFilename($sync), self::dispositionFilename($async));
    }

    public function test_変更前の名前で保存された出力も保存済みのパスのままダウンロードできる(): void
    {
        // issue #1 より前の出力は file_path が monthly-{month}.csv のまま残る。ダウンロードは保存済みのパスを読むので 404 にならない
        $export = ReportExport::factory()->completed()->create(['month' => '2026-08']);
        $legacy = "exports/{$this->company->id}/{$export->id}/monthly-2026-08.csv";
        $export->forceFill(['file_path' => $legacy])->save();
        Storage::disk('local')->put($legacy, "\xEF\xBB\xBFold");

        $response = $this->getAsGuest(self::downloadUrl($export));

        $response->assertOk();
        $this->assertSame('monthly-2026-08.csv', self::dispositionFilename($response));
        $this->assertSame("\xEF\xBB\xBFold", $response->streamedContent());
    }

    /**
     * Content-Disposition の filename を取り出す（引用符の有無どちらにも対応）
     */
    private static function dispositionFilename(TestResponse $response): ?string
    {
        $header = (string) $response->headers->get('Content-Disposition');

        return preg_match('/filename="?([^";]+)"?/', $header, $m) === 1 ? $m[1] : null;
    }

    public function test_31分後のurlは403(): void
    {
        $export = ReportExport::factory()->completed()->create();
        Storage::disk('local')->put($export->file_path, 'csv');
        $url = self::downloadUrl($export);

        $this->travel(31)->minutes();

        $this->getAsGuest($url)->assertStatus(403)
            ->assertExactJson(['message' => 'リンクの有効期限が切れたか、正しくありません。', 'errors' => []]);
    }

    public function test_signatureを改ざんしたurlは403(): void
    {
        $export = ReportExport::factory()->completed()->create();
        Storage::disk('local')->put($export->file_path, 'csv');
        $url = self::downloadUrl($export);

        $this->getAsGuest(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url))->assertStatus(403)
            ->assertJsonPath('message', 'リンクの有効期限が切れたか、正しくありません。');
        $this->getAsGuest(preg_replace('/expires=\d+/', 'expires='.now()->addYear()->getTimestamp(), $url))->assertStatus(403);
        $this->getAsGuest(strtok($url, '?'))->assertStatus(403);
    }

    public function test_署名の対象のidを他社の出力に書き換えると403(): void
    {
        $mine = ReportExport::factory()->completed()->create();
        $other = Company::factory()->create();
        $theirs = CurrentCompany::runAs($other->id, function () {
            $export = ReportExport::factory()->completed()->create();
            Storage::disk('local')->put($export->file_path, '他社の CSV');

            return $export;
        });
        $url = self::downloadUrl($mine);

        $this->getAsGuest(str_replace("/report-exports/{$mine->id}/", "/report-exports/{$theirs->id}/", $url))->assertStatus(403);
    }

    public function test_完了していない出力の署名付きurlは404(): void
    {
        $export = ReportExport::factory()->processing()->create();

        $this->getAsGuest(self::downloadUrl($export))->assertStatus(404);
    }

    public function test_ダウンロードのルートはsignedでauth_sanctumの外(): void
    {
        $route = app('router')->getRoutes()->getByName('report-exports.download');

        $this->assertNotNull($route);
        $this->assertContains('signed', $route->gatherMiddleware());
        $this->assertNotContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertNotContains('company', $route->gatherMiddleware());

        foreach (['report-exports.index', 'report-exports.store', 'report-exports.show'] as $name) {
            $middleware = app('router')->getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, $name);
            $this->assertContains('company', $middleware, $name);
        }
    }

    // ---- 通知 ----

    public function test_出力完了通知はdatabaseに残りリンクは管理画面の出力一覧(): void
    {
        $export = ReportExport::factory()->completed()->create(['month' => '2026-09']);

        $notification = new ReportExportReady($export->id, '2026-09', 3);
        $mail = $notification->toMail($this->admin);
        $data = $notification->toArray($this->admin);

        $this->assertSame(['mail', 'database'], $notification->via($this->admin));
        $this->assertStringContainsString('2026-09', $mail->subject);
        $this->assertSame(config('app.frontend_url').'/reports/exports', $mail->actionUrl);
        $this->assertStringNotContainsString('signature', implode("\n", $mail->introLines));
        $this->assertSame(['report_export_id' => $export->id, 'month' => '2026-09', 'row_count' => 3], $data);
    }

    public function test_出力完了の通知はnotificationsテーブルに管理者宛てで残る(): void
    {
        $this->shift('2026-09-10', Location::factory()->create());

        $this->requestExport('2026-09')->assertStatus(202);

        $export = ReportExport::query()->sole();
        $row = DB::table('notifications')->sole();
        $this->assertSame('user', $row->notifiable_type);
        $this->assertSame($this->admin->id, (int) $row->notifiable_id);
        $this->assertSame(['report_export_id' => $export->id, 'month' => '2026-09', 'row_count' => 1], json_decode($row->data, true));
    }

    public function test_別の管理者が依頼した出力の完了通知は依頼者に届く(): void
    {
        Notification::fake();
        $colleague = User::factory()->for($this->company)->create();
        $this->actingAs($colleague)->postJson('/api/report-exports', ['month' => '2026-09'])->assertStatus(202);

        Notification::assertSentTo($colleague, ReportExportReady::class);
        Notification::assertNotSentTo($this->admin, ReportExportReady::class);
    }
}
