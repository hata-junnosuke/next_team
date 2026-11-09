<?php

namespace Tests\Feature;

use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Models\Application;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\NotificationLog;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerInvitation;
use App\Services\NotificationLogService;
use App\Support\CurrentCompany;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * F-24 通知履歴（docs/01 の通知履歴の API）。GET /api/notification-logs?kind=&status=&page=
 *
 * NotificationLogResource: {id, kind: {value, label}, recipient: {type, name, email}, subject: {type, id, label},
 * status: {value, label}, sent_at, failed_at, error, created_at}
 */
class NotificationLogTest extends TestCase
{
    /**
     * 確定済みの応募に対する、そのワーカーへの確定通知
     */
    private function confirmedLog(string $jobTitle = '検品', string $workDate = '2026-10-10', string $workerName = '山田 太郎'): NotificationLog
    {
        $posting = JobPosting::factory()->create(['job_title' => $jobTitle, 'work_date' => $workDate]);
        $application = Application::factory()
            ->for($posting)
            ->for(Worker::factory()->state(['name' => $workerName, 'email' => 'yamada@example.com']))
            ->confirmed()
            ->create();

        return NotificationLog::factory()->state(['subject_id' => $application->id])->create();
    }

    private function exportLog(string $month = '2026-09'): NotificationLog
    {
        return NotificationLog::factory()->forExport()->state([
            'subject_id' => ReportExport::factory()->for($this->admin)->state(['month' => $month]),
        ])->create();
    }

    /**
     * ワーカーへの招待の通知（宛先はワーカー、対象は招待。SendWorkerInvitationMail と同じく claim() で作る）
     * email を省くとワーカーごとに別のアドレスになる。email を期待値で見るテストは明示して渡す
     */
    private function invitationLog(string $workerName = '鈴木 一郎', ?string $email = null): NotificationLog
    {
        $worker = Worker::factory()->create(['name' => $workerName, 'email' => $email ?? fake()->unique()->safeEmail()]);
        $invitation = WorkerInvitation::factory()->for($worker)->create();

        return app(NotificationLogService::class)->claim(NotificationKind::WorkerInvitation, $worker, $invitation);
    }

    public function test_通知履歴が新しい順にページングで返る(): void
    {
        $this->travelTo('2026-10-01 09:00:00');
        $old = $this->confirmedLog();
        $this->travelTo('2026-10-01 10:00:00');
        $middle = $this->exportLog();
        $this->travelTo('2026-10-01 11:00:00');
        $new = $this->confirmedLog('梱包');

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.1.id', $middle->id)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total'], 'links']);

        $this->getJson('/api/notification-logs?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $old->id);
    }

    public function test_同じ時刻なら_idの大きい順(): void
    {
        $this->travelTo('2026-10-01 09:00:00');
        $first = $this->confirmedLog();
        $second = $this->confirmedLog();

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);
    }

    public function test_宛先と対象がラベルつきで返る(): void
    {
        $this->travelTo('2026-10-01 09:00:00');
        $log = $this->confirmedLog('検品', '2026-10-10', '山田 太郎');

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', [
                'id' => $log->id,
                'kind' => ['value' => 'application_confirmed', 'label' => '確定通知'],
                'recipient' => ['type' => ['value' => 'worker', 'label' => 'ワーカー'], 'name' => '山田 太郎', 'email' => 'yamada@example.com'],
                'subject' => ['type' => ['value' => 'application', 'label' => '応募'], 'id' => $log->subject_id, 'label' => '検品 2026/10/10（土） / 山田 太郎'],
                'status' => ['value' => 'pending', 'label' => '送信待ち'],
                'sent_at' => null,
                'failed_at' => null,
                'error' => null,
                'created_at' => '2026-10-01T09:00:00+09:00',
            ]);
    }

    public function test_宛先が管理者の出力完了通知は月次集計のラベルで返る(): void
    {
        $log = $this->exportLog('2026-09');

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonPath('data.0.kind', ['value' => 'export_ready', 'label' => '出力完了'])
            ->assertJsonPath('data.0.recipient', ['type' => ['value' => 'user', 'label' => '管理者'], 'name' => $this->admin->name, 'email' => $this->admin->email])
            ->assertJsonPath('data.0.subject', ['type' => ['value' => 'report_export', 'label' => '集計出力'], 'id' => $log->subject_id, 'label' => '2026-09 の月次集計']);
    }

    public function test_送信済みと失敗の日時とエラーが返る(): void
    {
        $this->travelTo('2026-10-01 09:00:00');
        $sent = NotificationLog::factory()->sent()->create();
        $this->travelTo('2026-10-01 10:00:00');
        NotificationLog::factory()->failed()->create();

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonPath('data.0.status', ['value' => 'failed', 'label' => '失敗'])
            ->assertJsonPath('data.0.failed_at', '2026-10-01T10:00:00+09:00')
            ->assertJsonPath('data.0.sent_at', null)
            ->assertJsonPath('data.0.error', 'TransportException: メールの送信に失敗しました。')
            ->assertJsonPath('data.1.id', $sent->id)
            ->assertJsonPath('data.1.status', ['value' => 'sent', 'label' => '送信済み'])
            ->assertJsonPath('data.1.sent_at', '2026-10-01T09:00:00+09:00')
            ->assertJsonPath('data.1.failed_at', null);
    }

    public function test_宛先と対象が消えていても記録時のアドレスで返る(): void
    {
        $log = $this->confirmedLog();
        // 宛先・対象の行が存在しない状態を作る（morphs には外部キーがないので、削除されても記録は残る）
        NotificationLog::query()->whereKey($log->id)->update(['recipient_id' => 999999, 'subject_id' => 999999]);

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonPath('data.0.recipient', ['type' => ['value' => 'worker', 'label' => 'ワーカー'], 'name' => 'yamada@example.com', 'email' => 'yamada@example.com'])
            ->assertJsonPath('data.0.subject', ['type' => ['value' => 'application', 'label' => '応募'], 'id' => 999999, 'label' => '削除された応募']);
    }

    public function test_宛先の管理者を他社に付け替えたら名前が出ず記録時のアドレスで返る(): void
    {
        $this->exportLog();
        // User には BelongsToCompany（グローバルスコープ）がないので、読む側でも企業で絞っていることを確かめる
        $other = Company::factory()->create();
        $theirAdmin = User::factory()->for($other)->create(['name' => '他社 管理者']);
        NotificationLog::query()->update(['recipient_id' => $theirAdmin->id]);

        $response = $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonPath('data.0.recipient', ['type' => ['value' => 'user', 'label' => '管理者'], 'name' => $this->admin->email, 'email' => $this->admin->email]);
        // assertDontSee は JSON の \uXXXX エスケープ後の本文を見るので日本語には効かない。エスケープせずに比べる
        $this->assertStringNotContainsString('他社 管理者', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_招待の通知は対象がワーカー名への招待のラベルで返る(): void
    {
        $log = $this->invitationLog('鈴木 一郎', 'suzuki@example.com');

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', ['value' => 'worker_invitation', 'label' => '招待'])
            ->assertJsonPath('data.0.recipient', ['type' => ['value' => 'worker', 'label' => 'ワーカー'], 'name' => '鈴木 一郎', 'email' => 'suzuki@example.com'])
            ->assertJsonPath('data.0.subject', ['type' => ['value' => 'worker_invitation', 'label' => '招待'], 'id' => $log->subject_id, 'label' => '鈴木 一郎 への招待']);
    }

    public function test_招待の対象の行が消えていたら削除された招待で返る(): void
    {
        $log = $this->invitationLog();
        NotificationLog::query()->whereKey($log->id)->update(['subject_id' => 999999]);

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonPath('data.0.subject', ['type' => ['value' => 'worker_invitation', 'label' => '招待'], 'id' => 999999, 'label' => '削除された招待']);
    }

    public function test_招待のワーカーが読めないときは削除されたワーカーへの招待で他社のワーカー名が出ない(): void
    {
        // 読めない状態は「他社のワーカーを指す」で作る（グローバルスコープで読まれない）
        $log = $this->invitationLog('鈴木 一郎');
        $other = Company::factory()->create();
        $theirWorker = CurrentCompany::runAs($other->id, fn () => Worker::factory()->create(['name' => '他社 ワーカー']));
        WorkerInvitation::query()->whereKey($log->subject_id)->update(['worker_id' => $theirWorker->id]);

        $response = $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonPath('data.0.subject.label', '削除されたワーカー への招待');
        $this->assertStringNotContainsString('他社 ワーカー', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_種類を招待で絞り込める(): void
    {
        $invitation = $this->invitationLog();
        $this->confirmedLog();
        $this->exportLog();

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs?kind=worker_invitation')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invitation->id)
            ->assertJsonPath('data.0.kind.value', 'worker_invitation');
    }

    public function test_kindとstatusで絞り込める(): void
    {
        $confirmedSent = NotificationLog::factory()->sent()->create();
        NotificationLog::factory()->failed()->create();
        $exportFailed = NotificationLog::factory()->forExport()->failed()->create();

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs?kind=application_confirmed&status=sent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $confirmedSent->id);

        $this->getJson('/api/notification-logs?kind=export_ready')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $exportFailed->id);

        $this->getJson('/api/notification-logs?status=failed')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // 空は絞り込まない
        $this->getJson('/api/notification-logs?kind=&status=')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_不正なkindとstatusは422(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/notification-logs?kind=unknown&status=done')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kind', 'status']);
    }

    public function test_他社の通知履歴は見えない(): void
    {
        $mine = $this->confirmedLog();
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, function () {
            NotificationLog::factory()->create();
            NotificationLog::factory()->forExport()->create();
        });

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/notification-logs?kind=export_ready')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_ログインしていなければ401(): void
    {
        $this->getJson('/api/notification-logs')->assertUnauthorized();
    }

    public function test_通知履歴はn_1にならない(): void
    {
        $make = function () {
            $this->confirmedLog();
            NotificationLog::factory()->state(['kind' => NotificationKind::PostingCancelled])->create();
            $this->exportLog();
            $this->invitationLog();
        };
        $make();
        $this->actingAsAdmin()->getJson('/api/notification-logs')->assertOk();   // 認証などの初回分を済ませる

        DB::enableQueryLog();
        $this->getJson('/api/notification-logs')->assertOk()->assertJsonCount(4, 'data');
        $few = count(DB::getQueryLog());

        $make();
        $make();
        DB::flushQueryLog();
        $this->getJson('/api/notification-logs')->assertOk()->assertJsonCount(12, 'data');
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($few, $many);
    }

    // ---- テスト補強（tester） ----

    /**
     * 本番の 5 種の通知の記録を、ジョブと同じく NotificationLogService::claim() で作る（宛先・対象の組み合わせが本番と同じになる）
     *
     * @return array<string, NotificationLog>
     */
    private function claimAllKinds(): array
    {
        $service = app(NotificationLogService::class);
        $posting = JobPosting::factory()->create(['job_title' => 'ピッキング', 'work_date' => '2026-09-20']);
        $worker = Worker::factory()->create(['name' => '佐藤 花子', 'email' => 'sato@example.com']);
        $application = Application::factory()->for($posting)->for($worker)->confirmed()->create();
        $export = ReportExport::factory()->for($this->admin)->create(['month' => '2026-08']);
        $invitation = WorkerInvitation::factory()->for($worker)->create();

        return [
            'application_confirmed' => $service->claim(NotificationKind::ApplicationConfirmed, $worker, $application),
            'posting_cancelled' => $service->claim(NotificationKind::PostingCancelled, $worker, $application),
            'shift_reminder' => $service->claim(NotificationKind::ShiftReminder, $worker, $application),
            'export_ready' => $service->claim(NotificationKind::ExportReady, $this->admin, $export),
            'worker_invitation' => $service->claim(NotificationKind::WorkerInvitation, $worker, $invitation),
        ];
    }

    public function test_5種の通知すべてで種別のラベルと対象のラベルが返る(): void
    {
        $logs = $this->claimAllKinds();
        $expected = [
            'application_confirmed' => ['確定通知', 'worker', 'ワーカー', 'application', '応募', 'ピッキング 2026/09/20（日） / 佐藤 花子'],
            'posting_cancelled' => ['中止通知', 'worker', 'ワーカー', 'application', '応募', 'ピッキング 2026/09/20（日） / 佐藤 花子'],
            'shift_reminder' => ['前日リマインド', 'worker', 'ワーカー', 'application', '応募', 'ピッキング 2026/09/20（日） / 佐藤 花子'],
            'export_ready' => ['出力完了', 'user', '管理者', 'report_export', '集計出力', '2026-08 の月次集計'],
            'worker_invitation' => ['招待', 'worker', 'ワーカー', 'worker_invitation', '招待', '佐藤 花子 への招待'],
        ];

        foreach ($expected as $kind => [$kindLabel, $recipientType, $recipientTypeLabel, $subjectType, $subjectTypeLabel, $subjectLabel]) {
            $log = $logs[$kind];
            $this->actingAsAdmin()
                ->getJson("/api/notification-logs?kind={$kind}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $log->id)
                ->assertJsonPath('data.0.kind', ['value' => $kind, 'label' => $kindLabel])
                ->assertJsonPath('data.0.recipient.type', ['value' => $recipientType, 'label' => $recipientTypeLabel])
                ->assertJsonPath('data.0.subject', ['type' => ['value' => $subjectType, 'label' => $subjectTypeLabel], 'id' => $log->subject_id, 'label' => $subjectLabel]);
        }
    }

    public function test_宛先がワーカーと管理者の両方とも名前と記録時のアドレスで返る(): void
    {
        $logs = $this->claimAllKinds();

        $response = $this->actingAsAdmin()->getJson('/api/notification-logs')->assertOk()->assertJsonCount(5, 'data');

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertSame(['type' => ['value' => 'worker', 'label' => 'ワーカー'], 'name' => '佐藤 花子', 'email' => 'sato@example.com'], $byId[$logs['shift_reminder']->id]['recipient']);
        $this->assertSame(['type' => ['value' => 'user', 'label' => '管理者'], 'name' => $this->admin->name, 'email' => $this->admin->email], $byId[$logs['export_ready']->id]['recipient']);
    }

    public function test_宛先の管理者の行が消えていても記録時のアドレスと削除された集計出力で返る(): void
    {
        $log = $this->exportLog();
        NotificationLog::query()->whereKey($log->id)->update([
            'recipient_id' => 999999,
            'subject_id' => 999999,
            'recipient_email' => 'old-admin@example.com',
        ]);

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonPath('data.0.recipient', ['type' => ['value' => 'user', 'label' => '管理者'], 'name' => 'old-admin@example.com', 'email' => 'old-admin@example.com'])
            ->assertJsonPath('data.0.subject', ['type' => ['value' => 'report_export', 'label' => '集計出力'], 'id' => 999999, 'label' => '削除された集計出力']);
    }

    public function test_対象の種類は応募と集計出力と招待の3つだけが返る(): void
    {
        // 通知 5 種の対象は応募か集計出力か招待だけ（docs/01 の 3.2）。募集は morph map にないので対象にできない
        // 募集を対象にできないことは AsyncFoundationTest の morph map のテストで固定している
        $this->claimAllKinds();

        $response = $this->actingAsAdmin()->getJson('/api/notification-logs')->assertOk()->assertJsonCount(5, 'data');

        $this->assertEqualsCanonicalizing(
            ['application', 'report_export', 'worker_invitation'],
            collect($response->json('data'))->pluck('subject.type.value')->unique()->values()->all(),
        );
    }

    public function test_宛先と対象の種類のラベルに不明や募集が出ない(): void
    {
        // docs/01 の NotificationLogResource: type の label は「ワーカー」「管理者」「応募」「集計出力」「招待」の 5 つだけ（issue #25 で「募集」を外した。招待はワーカー Step 5）
        $this->claimAllKinds();
        // 行が消えた記録も混ぜる（ラベルは *_type から決まるので、行がなくても 5 つのどれかになる）
        NotificationLog::query()->update(['recipient_id' => 999999, 'subject_id' => 999999]);

        $rows = collect($this->actingAsAdmin()->getJson('/api/notification-logs')->assertOk()->assertJsonCount(5, 'data')->json('data'));

        $this->assertEqualsCanonicalizing(['ワーカー', '管理者'], $rows->pluck('recipient.type.label')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['応募', '集計出力', '招待'], $rows->pluck('subject.type.label')->unique()->values()->all());
        // type の label は上で確定しているので、残りは subject.label に「不明」が出ないことだけ
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('不明', $row['subject']['label']);
        }
    }

    public function test_応募の募集が読めないときは削除された募集とワーカー名のラベルで返る(): void
    {
        // job_posting_id は restrictOnDelete なので募集は消せない。読めない状態は「他社の募集を指す」で作る（グローバルスコープで読まれない）
        $log = $this->confirmedLog('検品', '2026-10-10', '山田 太郎');
        $other = Company::factory()->create();
        $theirPosting = CurrentCompany::runAs($other->id, fn () => JobPosting::factory()->create(['job_title' => '他社の職種']));
        Application::query()->whereKey($log->subject_id)->update(['job_posting_id' => $theirPosting->id]);

        $response = $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonPath('data.0.subject', ['type' => ['value' => 'application', 'label' => '応募'], 'id' => $log->subject_id, 'label' => '削除された募集 / 山田 太郎']);
        $this->assertStringNotContainsString('他社の職種', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_応募のワーカーが読めないときは削除されたワーカーのラベルで他社のワーカー名が出ない(): void
    {
        // 読めない状態は「他社のワーカーを指す」で作る（グローバルスコープで読まれない）
        $log = $this->confirmedLog('検品', '2026-10-10', '山田 太郎');
        $other = Company::factory()->create();
        $theirWorker = CurrentCompany::runAs($other->id, fn () => Worker::factory()->create(['name' => '他社 ワーカー']));
        Application::query()->whereKey($log->subject_id)->update(['worker_id' => $theirWorker->id]);

        $response = $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonPath('data.0.subject', ['type' => ['value' => 'application', 'label' => '応募'], 'id' => $log->subject_id, 'label' => '検品 2026/10/10（土） / 削除されたワーカー']);
        $this->assertStringNotContainsString('他社 ワーカー', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_宛先のアドレスが後から変わってもemailは記録時のアドレスで名前は今の名前(): void
    {
        $log = $this->confirmedLog('検品', '2026-10-10', '山田 太郎');
        Worker::query()->whereKey($log->recipient_id)->update(['email' => 'new@example.com', 'name' => '山田 一郎']);

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonPath('data.0.recipient', ['type' => ['value' => 'worker', 'label' => 'ワーカー'], 'name' => '山田 一郎', 'email' => 'yamada@example.com'])
            ->assertJsonPath('data.0.subject.label', '検品 2026/10/10（土） / 山田 一郎');
    }

    public function test_ラベルに入る名前や職種は記号を含んでもそのままの文字列で返る(): void
    {
        // エスケープは表示側（React）の仕事。API は加工せず JSON の文字列として返す（HTML エスケープや CSV の ' を付けない）
        $log = $this->confirmedLog('<b>検品</b> & "梱包"', '2026-10-10', '=HYPERLINK("x") <script>');

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonPath('data.0.recipient.name', '=HYPERLINK("x") <script>')
            ->assertJsonPath('data.0.subject.label', '<b>検品</b> & "梱包" 2026/10/10（土） / =HYPERLINK("x") <script>');
    }

    public function test_他社の通知履歴はcontextを消して叩いても見えない(): void
    {
        $mine = $this->exportLog();
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, function () {
            NotificationLog::factory()->sent()->create();
            NotificationLog::factory()->forExport()->failed()->create();
        });

        // テストの setUp が入れた企業を消し、ミドルウェアがログイン中の管理者から企業を決めることを確かめる
        Context::forget('company_id');

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('meta.total', 1);

        Context::forget('company_id');
        $this->getJson('/api/notification-logs?status=sent')->assertOk()->assertJsonCount(0, 'data');

        Context::forget('company_id');
        $this->getJson('/api/notification-logs?kind=export_ready&status=failed')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_他社の管理者には自社の通知履歴が見えない(): void
    {
        $this->confirmedLog();
        $other = Company::factory()->create();
        $otherAdmin = User::factory()->for($other)->create();
        Context::forget('company_id');

        $this->actingAs($otherAdmin)
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_per_pageは省略時20で最大100で0は1(): void
    {
        NotificationLog::factory()->count(101)->forExport()->create();

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 101);

        $this->getJson('/api/notification-logs?per_page=1000')
            ->assertOk()
            ->assertJsonCount(100, 'data')
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/notification-logs?per_page=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_kindとstatusの組み合わせはすべての組で該当する行だけを返す(): void
    {
        $logs = $this->claimAllKinds();
        $service = app(NotificationLogService::class);
        $service->markSent($logs['application_confirmed']);
        $service->markFailed($logs['posting_cancelled'], new TransportException('x'));
        $service->markFailed($logs['export_ready'], null);
        $service->markSent($logs['worker_invitation']);
        // shift_reminder は pending のまま

        $statusOf = [
            'application_confirmed' => 'sent',
            'posting_cancelled' => 'failed',
            'shift_reminder' => 'pending',
            'export_ready' => 'failed',
            'worker_invitation' => 'sent',
        ];

        $this->actingAsAdmin();
        foreach (NotificationKind::cases() as $kind) {
            foreach (NotificationStatus::cases() as $status) {
                $response = $this->getJson("/api/notification-logs?kind={$kind->value}&status={$status->value}")->assertOk();
                $expectedIds = $statusOf[$kind->value] === $status->value ? [$logs[$kind->value]->id] : [];
                $this->assertSame($expectedIds, array_column($response->json('data'), 'id'), "{$kind->value} × {$status->value}");
                foreach ($response->json('data') as $row) {
                    $this->assertSame($kind->value, $row['kind']['value']);
                    $this->assertSame($status->value, $row['status']['value']);
                }
            }
        }

        $this->getJson('/api/notification-logs?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', ['value' => 'pending', 'label' => '送信待ち']);
    }

    public function test_絞り込みとページングを組み合わせられる(): void
    {
        NotificationLog::factory()->count(3)->forExport()->failed()->create();
        NotificationLog::factory()->count(2)->sent()->create();

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs?status=failed&per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.status.value', 'failed');
    }

    public function test_kindやstatusを配列で渡すと422(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/notification-logs?kind[]=export_ready&status[]=sent')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kind', 'status'])
            ->assertJsonStructure(['message', 'errors' => ['kind', 'status']]);
    }

    public function test_不正なkindだけなら422のerrorsはkindだけ(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/notification-logs?kind=APPLICATION_CONFIRMED&status=sent')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kind'])
            ->assertJsonMissingValidationErrors(['status']);
    }

    public function test_失敗の記録のerrorは画面に生の例外メッセージを出さない(): void
    {
        // 記録の側（NotificationLogService::markFailed）の契約を、API の出口でも確かめる
        $service = app(NotificationLogService::class);
        $cases = [
            'QueryException: データベースの処理に失敗しました。' => new QueryException('sqlite', 'select * from workers where email = ?', ['secret@example.com'], new Exception('database is locked')),
            'TransportException: メールの送信に失敗しました。' => new TransportException('535 auth failed for secret@example.com password=secret'),
            'RuntimeException: 処理に失敗しました。' => new RuntimeException('token=secret'),
            '処理に失敗しました。（例外の情報なし）' => null,
        ];

        $expectedById = [];
        foreach ($cases as $expected => $e) {
            $application = Application::factory()->confirmed()->create();
            $log = $service->claim(NotificationKind::ApplicationConfirmed, $application->worker, $application);
            $service->markFailed($log, $e);
            $expectedById[$log->id] = $expected;
        }

        $response = $this->actingAsAdmin()->getJson('/api/notification-logs?status=failed')->assertOk()->assertJsonCount(4, 'data');

        foreach ($response->json('data') as $row) {
            $this->assertSame($expectedById[$row['id']], $row['error']);
            $this->assertStringNotContainsString('secret', $row['error']);
            $this->assertNotNull($row['failed_at']);
            $this->assertNull($row['sent_at']);
        }
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_一覧の各行がdocs01の6_10の形をしている(): void
    {
        $this->claimAllKinds();

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['*' => [
                    'id',
                    'kind' => ['value', 'label'],
                    'recipient' => ['type' => ['value', 'label'], 'name', 'email'],
                    'subject' => ['type' => ['value', 'label'], 'id', 'label'],
                    'status' => ['value', 'label'],
                    'sent_at', 'failed_at', 'error', 'created_at',
                ]],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_通知履歴は読み取りだけで作成や削除のルートはない(): void
    {
        $log = $this->confirmedLog();

        $this->actingAsAdmin()->postJson('/api/notification-logs', [])->assertStatus(405);
        $this->getJson("/api/notification-logs/{$log->id}")->assertNotFound();
        $this->deleteJson("/api/notification-logs/{$log->id}")->assertNotFound();
        $this->assertDatabaseHas('notification_logs', ['id' => $log->id]);
    }

    // ---- テスト補強（tester・issue #37 T6 招待） ----

    public function test_招待のラベルのワーカー名は今の名前で出てemailは記録時のアドレス(): void
    {
        // docs/01 の 6.10: recipient.name と label の名前は今の値、recipient.email は記録時の recipient_email
        $log = $this->invitationLog('鈴木 一郎', 'suzuki@example.com');
        Worker::query()->whereKey($log->recipient_id)->update(['name' => '鈴木 二郎', 'email' => 'new@example.com']);

        $this->actingAsAdmin()
            ->getJson('/api/notification-logs')
            ->assertOk()
            ->assertJsonPath('data.0.recipient', ['type' => ['value' => 'worker', 'label' => 'ワーカー'], 'name' => '鈴木 二郎', 'email' => 'suzuki@example.com'])
            ->assertJsonPath('data.0.subject.label', '鈴木 二郎 への招待');
    }

    public function test_使用済みや取り消しや期限切れの招待でもワーカー名への招待のラベルで返る(): void
    {
        // 招待の状態はラベルに関係しない（送り直しで前の招待が取り消されても、過去の送信の記録は読める）
        $service = app(NotificationLogService::class);
        $worker = Worker::factory()->create(['name' => '鈴木 一郎']);
        foreach (['used', 'revoked', 'expired'] as $state) {
            $service->claim(NotificationKind::WorkerInvitation, $worker, WorkerInvitation::factory()->for($worker)->{$state}()->create());
        }

        $rows = $this->actingAsAdmin()
            ->getJson('/api/notification-logs?kind=worker_invitation')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->json('data');

        foreach ($rows as $row) {
            $this->assertSame(['value' => 'worker_invitation', 'label' => '招待'], $row['subject']['type']);
            $this->assertSame('鈴木 一郎 への招待', $row['subject']['label']);
        }
    }

    public function test_他社の招待の通知は見えず他社のワーカー名も出ない(): void
    {
        $mine = $this->invitationLog('鈴木 一郎');
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, function () {
            $service = app(NotificationLogService::class);
            $worker = Worker::factory()->create(['name' => '他社 ワーカー']);
            $log = $service->claim(NotificationKind::WorkerInvitation, $worker, WorkerInvitation::factory()->for($worker)->create());
            $service->markSent($log);
        });

        $response = $this->actingAsAdmin()
            ->getJson('/api/notification-logs?kind=worker_invitation')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('meta.total', 1);
        $this->assertStringNotContainsString('他社 ワーカー', json_encode($response->json(), JSON_UNESCAPED_UNICODE));

        // 他社の記録は送信済み。自社の記録は pending なので、招待 × 送信済みは 0 件
        $this->getJson('/api/notification-logs?kind=worker_invitation&status=sent')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_種類を招待で状態と組み合わせて絞り込める(): void
    {
        $service = app(NotificationLogService::class);
        $sent = $this->invitationLog('鈴木 一郎');
        $service->markSent($sent);
        $failed = $this->invitationLog('佐藤 花子');
        $service->markFailed($failed, new TransportException('x'));
        $pending = $this->invitationLog('田中 次郎');
        // ほかの種類の送信済みは、招待 × 送信済みに混ざらない
        NotificationLog::factory()->sent()->create();

        $this->actingAsAdmin();
        foreach (['sent' => $sent, 'failed' => $failed, 'pending' => $pending] as $status => $log) {
            $this->getJson("/api/notification-logs?kind=worker_invitation&status={$status}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $log->id)
                ->assertJsonPath('data.0.kind.value', 'worker_invitation')
                ->assertJsonPath('data.0.status.value', $status);
        }
        $this->getJson('/api/notification-logs?kind=worker_invitation')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_招待の通知履歴の応答に招待コードもcode_hashも出ない(): void
    {
        // 招待コードは平文で持たず、画面にも出さない（docs/01 の 3.2・.claude/rules/common/security.md）
        $service = app(NotificationLogService::class);
        $worker = Worker::factory()->create(['name' => '鈴木 一郎', 'email' => 'suzuki@example.com']);
        $invitation = WorkerInvitation::factory()->for($worker)->create(['code' => 'ABCD2345']);
        $codeHash = WorkerInvitation::hashCode('ABCD2345');
        $this->assertSame($codeHash, WorkerInvitation::query()->whereKey($invitation->id)->value('code_hash'));
        $service->markSent($service->claim(NotificationKind::WorkerInvitation, $worker, $invitation));

        $response = $this->actingAsAdmin()
            ->getJson('/api/notification-logs?kind=worker_invitation')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject.id', $invitation->id);

        $body = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('ABCD2345', $body);
        $this->assertStringNotContainsString($codeHash, $body);
        // キー名としても出ない（"code": と "code_hash": を足していない）。値の中の文字列には当てない
        $this->assertStringNotContainsString('"code', $body);
    }

    public function test_招待の通知が増えてもクエリ数は一定(): void
    {
        // 招待のワーカー（ラベルに使う）を morphWith でまとめて読むことを、招待だけの一覧で確かめる
        $this->invitationLog('鈴木 一郎');
        $this->actingAsAdmin()->getJson('/api/notification-logs')->assertOk();   // 認証などの初回分を済ませる

        DB::enableQueryLog();
        $this->getJson('/api/notification-logs')->assertOk()->assertJsonCount(1, 'data');
        $few = count(DB::getQueryLog());

        foreach (range(1, 5) as $i) {
            $this->invitationLog("ワーカー {$i}");
        }
        DB::flushQueryLog();
        $this->getJson('/api/notification-logs')->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.subject.label', 'ワーカー 5 への招待');
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($few, $many);
    }
}
