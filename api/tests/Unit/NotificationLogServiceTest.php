<?php

namespace Tests\Unit;

use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Models\Application;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\Worker;
use App\Services\NotificationLogService;
use App\Support\CurrentCompany;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * 通知の記録（docs/03 4 章の冪等性）。メールを送るジョブは claim → 送信 → markSent の順に呼ぶ。
 * claim の動き: 行がなければ pending で作る / sent なら null（送らない）/ pending・failed なら同じ行（送り直してよい）。
 * DB を使うので Tests\TestCase（RefreshDatabase と CurrentCompany の設定済み）を使う。
 */
class NotificationLogServiceTest extends TestCase
{
    private NotificationLogService $service;

    private Application $application;

    /** raceWithCompetitorRow() で「別のワーカー」が先に入れた行の ID */
    private ?int $competitorId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(NotificationLogService::class);
        $this->application = Application::factory()->confirmed()->create();
    }

    public function test_claimは行がなければpendingで作って返す(): void
    {
        $worker = $this->application->worker;

        $log = $this->claim();

        $this->assertNotNull($log);
        $this->assertTrue($log->exists);
        $this->assertSame(NotificationStatus::Pending, $log->status);
        $this->assertSame(NotificationKind::ApplicationConfirmed, $log->kind);
        $this->assertSame("application_confirmed:application:{$this->application->id}", $log->dedupe_key);
        $this->assertSame($worker->email, $log->recipient_email);
        $this->assertSame($this->company->id, $log->company_id);
        $this->assertTrue($log->recipient->is($worker));
        $this->assertTrue($log->subject->is($this->application));
    }

    public function test_宛先と対象にはmorph_mapの短い名前が入る(): void
    {
        $log = $this->claim();

        $row = DB::table('notification_logs')->where('id', $log->id)->first();

        $this->assertSame('worker', $row->recipient_type);
        $this->assertSame('application', $row->subject_type);
    }

    public function test_claimは送信済みならnullを返す(): void
    {
        $log = $this->claim();
        $this->service->markSent($log);

        $this->assertNull($this->claim());
        $this->assertSame(1, NotificationLog::query()->count());
    }

    public function test_claimはpendingなら同じ行を返す(): void
    {
        $first = $this->claim();

        $second = $this->claim();

        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, NotificationLog::query()->count());
    }

    public function test_claimはfailedなら同じ行を返す(): void
    {
        $first = $this->claim();
        $this->service->markFailed($first, new RuntimeException('SMTP に接続できません'));

        $second = $this->claim();

        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(NotificationStatus::Failed, $second->status);
        $this->assertSame(1, NotificationLog::query()->count());
    }

    public function test_claimで送り直すときは宛先を今のメールアドレスにする(): void
    {
        $this->claim();
        $this->application->worker->update(['email' => 'new-address@example.com']);

        $log = $this->claim();

        $this->assertSame('new-address@example.com', $log->recipient_email);
        $this->assertSame('new-address@example.com', $log->fresh()->recipient_email);
    }

    public function test_mark_sentはsentとsent_atを残す(): void
    {
        $this->freezeSecond();
        $log = $this->claim();

        $this->service->markSent($log);

        $log->refresh();
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertTrue($log->sent_at->equalTo(now()));
    }

    public function test_mark_failedはfailed_atとerrorを残す(): void
    {
        $this->freezeSecond();
        $log = $this->claim();

        $this->service->markFailed($log, new RuntimeException('SMTP に接続できません'));

        $log->refresh();
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertTrue($log->failed_at->equalTo(now()));
        $this->assertSame('RuntimeException: 処理に失敗しました。', $log->error);
        $this->assertNull($log->sent_at);
    }

    public function test_mark_failed_by_keyはキーの行をfailedにする(): void
    {
        $log = $this->claim();

        $this->service->markFailedByKey($log->dedupe_key, new RuntimeException('タイムアウト'));

        $log->refresh();
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertSame('RuntimeException: 処理に失敗しました。', $log->error);
    }

    public function test_mark_failed_by_keyは行がなければ何もしない(): void
    {
        $this->service->markFailedByKey('application_confirmed:application:999999', new RuntimeException('応募が見つからない'));

        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_mark_failed_by_keyは送信済みの行を失敗に戻さない(): void
    {
        $log = $this->claim();
        $this->service->markSent($log);

        $this->service->markFailedByKey($log->dedupe_key, new RuntimeException('遅れて届いた失敗'));

        $this->assertSame(NotificationStatus::Sent, $log->fresh()->status);
    }

    public function test_mark_failed_by_keyは行があればtrueなければfalseを返す(): void
    {
        $log = $this->claim();

        $this->assertTrue($this->service->markFailedByKey($log->dedupe_key, null));
        $this->assertFalse($this->service->markFailedByKey('application_confirmed:application:999999', null));
    }

    // ---- recordFailure（ジョブの failed() で、claim の前に打ち切られた通知の記録を作る） ----

    public function test_record_failureは行がなければfailedの行を作る(): void
    {
        $this->freezeSecond();

        $this->service->recordFailure(NotificationKind::ApplicationConfirmed, $this->application->worker, $this->application, new RuntimeException('期限切れ'));

        $log = NotificationLog::query()->sole();
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertSame(NotificationKind::ApplicationConfirmed, $log->kind);
        $this->assertSame($this->application->worker->email, $log->recipient_email);
        $this->assertTrue($log->failed_at->equalTo(now()));
        $this->assertSame('RuntimeException: 処理に失敗しました。', $log->error);
    }

    public function test_record_failureは送信済みの行を失敗に戻さない(): void
    {
        $log = $this->claim();
        $this->service->markSent($log);

        $this->service->recordFailure(NotificationKind::ApplicationConfirmed, $this->application->worker, $this->application, null);

        $this->assertSame(NotificationStatus::Sent, $log->fresh()->status);
        $this->assertSame(1, NotificationLog::query()->count());
    }

    public function test_record_failureも他社の宛先なら例外で行を作らない(): void
    {
        $other = Company::factory()->create();
        $theirWorker = CurrentCompany::runAs($other->id, fn () => Worker::factory()->create());

        $this->expectException(LogicException::class);
        try {
            $this->service->recordFailure(NotificationKind::ApplicationConfirmed, $theirWorker, $this->application, null);
        } finally {
            $this->assertSame(0, NotificationLog::withoutGlobalScopes()->count()); // 全社の行を数えるため
        }
    }

    public function test_別のワーカーが先に送信済みにしていたらmark_sentは何もせずsent_atは最初のまま(): void
    {
        $this->freezeSecond();
        $log = $this->claim();
        $stale = NotificationLog::query()->findOrFail($log->id);
        $this->service->markSent($log);
        $firstSentAt = $log->fresh()->sent_at;
        $this->travel(1)->minutes();

        $this->service->markSent($stale);

        $fresh = $log->fresh();
        $this->assertSame(NotificationStatus::Sent, $fresh->status);
        $this->assertTrue($fresh->sent_at->equalTo($firstSentAt));
    }

    public function test_送信済みの記録にmark_failedを呼んでも失敗に戻さない(): void
    {
        $log = $this->claim();
        $stale = NotificationLog::query()->findOrFail($log->id);
        $this->service->markSent($log);

        $this->service->markFailed($stale, new RuntimeException('遅れて届いた失敗'));

        $fresh = $log->fresh();
        $this->assertSame(NotificationStatus::Sent, $fresh->status);
        $this->assertNull($fresh->error);
        $this->assertNull($fresh->failed_at);
    }

    public function test_claimに他社のワーカーや応募を渡すとlogic_exceptionで行は作られない(): void
    {
        $other = Company::factory()->create();
        [$theirWorker, $theirApplication] = CurrentCompany::runAs($other->id, function () {
            $application = Application::factory()->confirmed()->create();

            return [$application->worker()->first(), $application];
        });
        $myWorker = $this->application->worker()->first();

        $cases = [
            '他社のワーカー' => [$theirWorker, $this->application],
            '他社の応募' => [$myWorker, $theirApplication],
        ];
        foreach ($cases as $label => [$recipient, $subject]) {
            try {
                $this->service->claim(NotificationKind::ApplicationConfirmed, $recipient, $subject);
                $this->fail("{$label}で行を作れてしまった");
            } catch (LogicException) {
                $this->assertSame(0, DB::table('notification_logs')->count(), $label);
            }
        }
    }

    public function test_dedupe_keyの重複はdbが拒否する(): void
    {
        $log = $this->claim();
        $row = (array) DB::table('notification_logs')->where('id', $log->id)->first();
        unset($row['id']);

        $this->expectException(UniqueConstraintViolationException::class);

        // モデルとサービスを通さずに同じキーの行を入れる（claim の検索をすり抜けた同時実行に相当）
        DB::table('notification_logs')->insert($row);
    }

    public function test_claimに他社の管理者や集計出力を渡すとlogic_exceptionで行は作られない(): void
    {
        $other = Company::factory()->create();
        $theirAdmin = User::factory()->for($other)->create();
        $theirExport = CurrentCompany::runAs($other->id, fn () => ReportExport::factory()->create(['user_id' => $theirAdmin->id]));
        $myExport = ReportExport::factory()->create(['user_id' => $this->admin->id]);

        $cases = [
            '他社の管理者' => [$theirAdmin, $myExport],
            '他社の集計出力' => [$this->admin, $theirExport],
        ];
        foreach ($cases as $label => [$recipient, $subject]) {
            $this->assertClaimRejected($label, $recipient, $subject, NotificationKind::ExportReady);
        }
    }

    public function test_claimにcompany_idを省いて読んだモデルを渡すとlogic_exceptionで行は作られない(): void
    {
        $worker = Worker::query()->select('id', 'email')->findOrFail($this->application->worker_id);
        $application = Application::query()->select('id', 'worker_id')->findOrFail($this->application->id);

        $this->assertClaimRejected('company_id のない宛先', $worker, $this->application);
        $this->assertClaimRejected('company_id のない対象', $this->application->worker()->first(), $application);
    }

    public function test_claimに未保存のモデルを渡すとlogic_exceptionで行は作られない(): void
    {
        $unsaved = Worker::factory()->make(['company_id' => $this->company->id]);

        $this->assertClaimRejected('未保存の宛先', $unsaved, $this->application);
    }

    public function test_claimに宛先のメールアドレスがないモデルを渡すとlogic_exceptionで行は作られない(): void
    {
        $worker = Worker::query()->select('id', 'company_id')->findOrFail($this->application->worker_id);

        $this->assertClaimRejected('メールアドレスのない宛先', $worker, $this->application);
    }

    public function test_claimは宛先のメールアドレスを宛先のモデルから決める(): void
    {
        $admin = User::factory()->for($this->company)->create(['email' => 'requester@example.com']);
        $export = ReportExport::factory()->create(['user_id' => $admin->id]);

        $log = $this->service->claim(NotificationKind::ExportReady, $admin, $export);

        $this->assertSame('requester@example.com', $log->recipient_email);
    }

    private function assertClaimRejected(string $label, Model $recipient, Model $subject, NotificationKind $kind = NotificationKind::ApplicationConfirmed): void
    {
        try {
            $this->service->claim($kind, $recipient, $subject);
            $this->fail("{$label}で行を作れてしまった");
        } catch (LogicException) {
            $this->assertSame(0, DB::table('notification_logs')->count(), $label);
        }
    }

    private function claim(): ?NotificationLog
    {
        $worker = $this->application->worker()->first();

        return $this->service->claim(NotificationKind::ApplicationConfirmed, $worker, $this->application);
    }

    public function test_同時にclaimして先に別のワーカーが行を作っていても行は1つにまとまり同じ行を返す(): void
    {
        $this->raceWithCompetitorRow(NotificationStatus::Pending);

        $log = $this->claim();

        $this->assertNotNull($this->competitorId, '競合する行が入っていない（テストの前提が崩れている）');
        $this->assertNotNull($log);
        $this->assertSame($this->competitorId, $log->id);
        $this->assertSame(1, NotificationLog::query()->count());
    }

    public function test_同時にclaimして先に別のワーカーが送信済みにしていたらnullを返す(): void
    {
        $this->raceWithCompetitorRow(NotificationStatus::Sent);

        $log = $this->claim();

        $this->assertNotNull($this->competitorId, '競合する行が入っていない（テストの前提が崩れている）');
        $this->assertNull($log);
        $this->assertSame(1, NotificationLog::query()->count());
    }

    public function test_failedから再送するとsentになりsent_atが入り以後のclaimはnull(): void
    {
        $this->freezeSecond();
        $first = $this->claim();
        $this->service->markFailed($first, new RuntimeException('SMTP に接続できません'));

        $retry = $this->claim();
        $this->service->markSent($retry);

        $fresh = $first->fresh();
        $this->assertSame(NotificationStatus::Sent, $fresh->status);
        $this->assertTrue($fresh->sent_at->equalTo(now()));
        $this->assertNull($this->claim());
        $this->assertSame(1, NotificationLog::query()->count());
    }

    public function test_failedのままもう一度失敗するとfailed_atとerrorを書き直す(): void
    {
        $log = $this->claim();
        $this->service->markFailed($log, new QueryException('sqlite', 'select 1', [], new Exception('database is locked')));
        $this->travel(5)->minutes();
        $this->freezeSecond();

        $this->service->markFailed($this->claim(), new TransportException('Connection refused'));

        $fresh = $log->fresh();
        $this->assertSame(NotificationStatus::Failed, $fresh->status);
        $this->assertTrue($fresh->failed_at->equalTo(now()));
        $this->assertSame('TransportException: メールの送信に失敗しました。', $fresh->error);
    }

    public function test_errorには例外のクラス名と固定の文言だけを残し生のメッセージは残さない(): void
    {
        $cases = [
            'QueryException: データベースの処理に失敗しました。' => new QueryException('sqlite', 'select * from workers where email = ?', ['secret@example.com'], new Exception('database is locked')),
            'TransportException: メールの送信に失敗しました。' => new TransportException('Expected response code 250 but got 535 for secret@example.com'),
            'RuntimeException: 処理に失敗しました。' => new RuntimeException('password=secret'),
        ];

        foreach ($cases as $expected => $e) {
            $log = $this->claim();
            $this->service->markFailed($log, $e);

            $error = $log->fresh()->error;
            $this->assertSame($expected, $error);
            $this->assertStringNotContainsString('secret', $error);
        }
    }

    public function test_mark_failed_by_keyは例外がnullでもfailedにしてerrorを残す(): void
    {
        $log = $this->claim();

        $this->service->markFailedByKey($log->dedupe_key, null);

        $fresh = $log->fresh();
        $this->assertSame(NotificationStatus::Failed, $fresh->status);
        $this->assertNotEmpty($fresh->error);
    }

    public function test_同じ応募でも通知の種類が違えば別の行になる(): void
    {
        $worker = $this->application->worker()->first();
        $confirmed = $this->claim();
        $this->service->markSent($confirmed);

        $cancelled = $this->service->claim(NotificationKind::PostingCancelled, $worker, $this->application);

        $this->assertNotNull($cancelled);
        $this->assertNotSame($confirmed->id, $cancelled->id);
        $this->assertSame("posting_cancelled:application:{$this->application->id}", $cancelled->dedupe_key);
        $this->assertSame(2, NotificationLog::query()->count());
    }

    public function test_出力完了は管理者宛てで対象は集計出力になりmorph_mapの短い名前が入る(): void
    {
        $export = ReportExport::factory()->create(['user_id' => $this->admin->id]);

        $log = $this->service->claim(NotificationKind::ExportReady, $this->admin, $export);

        $row = DB::table('notification_logs')->where('id', $log->id)->first();
        $this->assertSame("export_ready:report_export:{$export->id}", $log->dedupe_key);
        $this->assertSame('user', $row->recipient_type);
        $this->assertSame('report_export', $row->subject_type);
        $this->assertTrue($log->fresh()->recipient->is($this->admin));
        $this->assertTrue($log->fresh()->subject->is($export));
    }

    public function test_dedupe_keyはdocs03の4_2の形(): void
    {
        $export = ReportExport::factory()->create();

        $this->assertSame("application_confirmed:application:{$this->application->id}", NotificationLogService::dedupeKey(NotificationKind::ApplicationConfirmed, $this->application));
        $this->assertSame("shift_reminder:application:{$this->application->id}", NotificationLogService::dedupeKey(NotificationKind::ShiftReminder, $this->application));
        // 中止通知の対象は募集ではなく応募（宛先のワーカーごとに 1 通にするため）
        $this->assertSame("posting_cancelled:application:{$this->application->id}", NotificationLogService::dedupeKey(NotificationKind::PostingCancelled, $this->application));
        $this->assertSame("export_ready:report_export:{$export->id}", NotificationLogService::dedupeKey(NotificationKind::ExportReady, $export));
    }

    /**
     * 「2 つのワーカーが同時に claim した」状況を作る。
     * claim の最初の検索（行がない）が終わった直後に、別のワーカーが同じキーの行を入れたことにする。
     * その後の INSERT は UNIQUE 違反になり、createOrFirst が既存の行を読み直すはず。
     * DB::listen はクエリの実行後に呼ばれるので、最初の SELECT の結果（0 件）は変わらない。
     * 先に入った行の ID は $this->competitorId に入る。
     */
    private function raceWithCompetitorRow(NotificationStatus $status): void
    {
        $worker = $this->application->worker()->first();
        $row = [
            'company_id' => $this->company->id,
            'kind' => NotificationKind::ApplicationConfirmed->value,
            'recipient_type' => 'worker',
            'recipient_id' => $worker->id,
            'subject_type' => 'application',
            'subject_id' => $this->application->id,
            'recipient_email' => $worker->email,
            'dedupe_key' => NotificationLogService::dedupeKey(NotificationKind::ApplicationConfirmed, $this->application),
            'status' => $status->value,
            'sent_at' => $status === NotificationStatus::Sent ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $started = false;
        DB::listen(function (QueryExecuted $query) use (&$started, $row) {
            if (! $started && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'notification_logs')) {
                $started = true; // 下の INSERT で再入しないよう先に印を付ける
                $this->competitorId = DB::table('notification_logs')->insertGetId($row);
            }
        });
    }
}
