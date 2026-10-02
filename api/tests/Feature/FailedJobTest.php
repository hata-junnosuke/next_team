<?php

namespace Tests\Feature;

use App\Enums\CancelReason;
use App\Enums\JobPostingStatus;
use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Jobs\SendApplicationConfirmedMail;
use App\Jobs\SendPostingCancelledMail;
use App\Mail\ApplicationConfirmedMail;
use App\Mail\PostingCancelledMail;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\NotificationLog;
use App\Models\Worker;
use App\Services\NotificationLogService;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/**
 * 失敗が記録され、再実行できる（要件 7 章「失敗した送信は記録が残り、再実行できる」、docs/03 3.2・5 章。docs/01 9.1 の必須テスト）。
 * F-21 中止通知のジョブで確かめる。database キューに実際に積み、ワーカー（queue:work --once）で 1 回ずつ実行する
 * （maxExceptions / retryUntil / backoff / failed() / failed_jobs はワーカーが動かすので、handle() の直呼びでは確かめられない）。
 *
 * 記録はスコープを通さずに DB::table() で読む。ワーカーはテストと同じプロセスで動き、ジョブの Context をこのプロセスに
 * 戻したまま終わるので、モデル経由だとワーカーが戻した企業で読めてしまい、確かめたいことと混ざる。
 */
class FailedJobTest extends TestCase
{
    public function test_送信に失敗してfailedが呼ばれるとnotification_logsがfailedになりerrorが残る(): void
    {
        $application = $this->cancelledApplication('hanako@example.com');
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused (user=secret)'));

        SendPostingCancelledMail::dispatch($application->id)->onConnection('database');
        $this->failThreeTimes();

        // 3 回とも失敗 → キューから消え、failed_jobs に丸ごと 1 件残る
        $this->assertSame(0, DB::table('jobs')->count());
        $failed = DB::table('failed_jobs')->sole();
        $this->assertSame('mails', $failed->queue);
        $this->assertStringContainsString('SendPostingCancelledMail', $failed->payload);
        $this->assertStringContainsString('TransportException', $failed->exception);

        // 業務の記録は failed。error は例外のクラス名と固定の文言だけ（生のメッセージは残さない）
        $log = DB::table('notification_logs')->sole();
        $this->assertSame(NotificationKind::PostingCancelled->value, $log->kind);
        $this->assertSame(NotificationStatus::Failed->value, $log->status);
        $this->assertNotNull($log->failed_at);
        $this->assertNull($log->sent_at);
        $this->assertSame('TransportException: メールの送信に失敗しました。', $log->error);
        $this->assertStringNotContainsString('secret', $log->error);
        $this->assertSame($this->company->id, (int) $log->company_id);
    }

    public function test_失敗した通知をhandleで再実行するとsentになる(): void
    {
        $application = $this->cancelledApplication('hanako@example.com');
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused'));
        SendPostingCancelledMail::dispatch($application->id)->onConnection('database');
        $this->failThreeTimes();
        $this->assertSame(NotificationStatus::Failed->value, DB::table('notification_logs')->sole()->status);

        // メールサーバーが直った → 同じ応募のジョブをもう一度実行する（Horizon の再実行・手動の投入と同じ）
        $this->stopFailingMailer();
        Mail::fake();
        (new SendPostingCancelledMail($application->id))->handle(app(NotificationLogService::class));

        Mail::assertSent(PostingCancelledMail::class, 1);
        $log = DB::table('notification_logs')->sole(); // 行は増えず、failed の行が sent になる
        $this->assertSame(NotificationStatus::Sent->value, $log->status);
        $this->assertNotNull($log->sent_at);
    }

    public function test_3回失敗した後にqueue_retryで再実行すると1通送られsentになる(): void
    {
        $application = $this->cancelledApplication('hanako@example.com');
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused'));
        SendPostingCancelledMail::dispatch($application->id)->onConnection('database');
        $this->failThreeTimes();
        $this->assertSame(1, DB::table('failed_jobs')->count());

        // failed_jobs から再実行（php artisan queue:retry all。Horizon の「Retry」ボタンと同じ）
        $this->stopFailingMailer();
        Mail::fake();
        $this->artisan('queue:retry', ['id' => ['all']])->assertExitCode(0);
        Context::forget('company_id'); // 企業は再実行したジョブのペイロードの Context から戻る
        $this->work();

        Mail::assertSent(PostingCancelledMail::class, 1);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $log = DB::table('notification_logs')->sole();
        $this->assertSame(NotificationStatus::Sent->value, $log->status);
        $this->assertNotNull($log->sent_at);
        $this->assertSame($this->company->id, (int) $log->company_id);
    }

    public function test_1件の送信失敗は他の応募のジョブに影響しない(): void
    {
        // 中止 API が積むジョブを database キューに溜める（sync だとリクエストの中で実行される）
        config(['queue.default' => 'database']);
        $posting = JobPosting::factory()->create(['capacity' => 3]);
        $ids = [];
        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            $ids[$email] = Application::factory()->for($posting)->for(Worker::factory()->state(['email' => $email]))->confirmed()->create()->id;
        }
        // b さん宛てだけメールサーバーが拒否する（本物の array メーラーで送り、送る直前のイベントで例外を投げる）
        Event::listen(MessageSending::class, function (MessageSending $event) {
            if (in_array('b@example.com', array_map(fn ($address) => $address->getAddress(), $event->message->getTo()), true)) {
                throw new TransportException('Mailbox unavailable');
            }
        });

        $this->actingAsAdmin()->postJson("/api/job-postings/{$posting->id}/cancel")->assertOk();
        $this->assertSame(3, DB::table('jobs')->where('queue', 'mails')->count()); // 1 応募 1 ジョブ

        foreach (range(1, 3) as $_) {
            Context::forget('company_id');
            $this->work();
        }

        // a さんと c さんには届き、b さんのジョブだけが再試行待ち（backoff 10 秒）で残る
        $sentTo = collect($this->sentMessages())->flatMap(fn ($m) => array_map(fn ($a) => $a->getAddress(), $m->getOriginalMessage()->getTo()))->all();
        $this->assertEqualsCanonicalizing(['a@example.com', 'c@example.com'], $sentTo);
        $statuses = DB::table('notification_logs')->pluck('status', 'subject_id')->all();
        $this->assertSame(NotificationStatus::Sent->value, $statuses[$ids['a@example.com']]);
        $this->assertSame(NotificationStatus::Pending->value, $statuses[$ids['b@example.com']]);
        $this->assertSame(NotificationStatus::Sent->value, $statuses[$ids['c@example.com']]);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // b さんのジョブが最後まで失敗しても、a さんと c さんの記録は sent のまま（失敗したのは 1 つだけ）
        foreach ([11, 61] as $seconds) {
            $this->travel($seconds)->seconds();
            Context::forget('company_id');
            $this->work();
        }
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $statuses = DB::table('notification_logs')->pluck('status', 'subject_id')->all();
        $this->assertSame(NotificationStatus::Sent->value, $statuses[$ids['a@example.com']]);
        $this->assertSame(NotificationStatus::Failed->value, $statuses[$ids['b@example.com']]);
        $this->assertSame(NotificationStatus::Sent->value, $statuses[$ids['c@example.com']]);
        $this->assertCount(2, $this->sentMessages()); // a さんと c さんに 2 通目を送っていない
    }

    public function test_送るべき対象でない応募のジョブがfailedになっても通知の記録は作らない(): void
    {
        // 応募が見つからない（消えた・他社）か、もう中止通知の対象ではない。宛先に送る通知がないので
        // notification_logs の行は作らない（ジョブ自体は failed_jobs に残る）
        $notTarget = Application::factory()->confirmed()->create();

        (new SendPostingCancelledMail(999_999))->failed(new \RuntimeException('boom'));
        (new SendPostingCancelledMail($notTarget->id))->failed(new \RuntimeException('boom'));

        $this->assertSame(0, NotificationLog::withoutGlobalScopes()->count()); // 全社の行を数えるため
    }

    public function test_failedの中で記録に失敗しても例外を投げずに報告だけする(): void
    {
        // failed() から例外が漏れるとワーカーの失敗処理が途中で止まる。記録の失敗は report() してログに残すだけ
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (\Throwable $e) use (&$reported) {
            $reported[] = $e;
        });
        $application = $this->cancelledApplication('hanako@example.com');
        Context::forget('company_id'); // 企業が分からない → グローバルスコープが LogicException

        (new SendPostingCancelledMail($application->id))->failed(new \RuntimeException('boom'));

        $this->assertCount(1, $reported);
        $this->assertInstanceOf(\LogicException::class, $reported[0]);
        $this->assertSame(0, DB::table('notification_logs')->count());
    }

    public function test_送信済みの後にfailedが呼ばれても失敗に戻さない(): void
    {
        Mail::fake();
        $application = $this->cancelledApplication('hanako@example.com');
        (new SendPostingCancelledMail($application->id))->handle(app(NotificationLogService::class));

        (new SendPostingCancelledMail($application->id))->failed(new TransportException('late'));

        $this->assertSame(NotificationStatus::Sent, NotificationLog::query()->sole()->status);
    }

    // ---- 流量制限と打ち切り（P4-03 の差し戻し。tries=0 + maxExceptions + retryUntil） ----

    public function test_流量の上限で4回以上戻されてもfailedにならず枠が空いたら送られてsentになる(): void
    {
        Mail::fake();
        config(['myteam.mails_per_minute' => 1]);
        $application = $this->cancelledApplication('hanako@example.com');
        SendPostingCancelledMail::dispatch($application->id)->onConnection('database');

        // 毎分の枠（1 通）を、先に流した別の応募の確定通知が使い切る状態を 5 回続ける（リミッター mails は全メールのジョブで共通）
        foreach (range(1, 5) as $_) {
            $this->sendOtherMailFirst();
            Context::forget('company_id');
            $this->work();
            $this->travel(70)->seconds();
        }

        // 5 回戻されても失敗にしない（tries=3 だと 4 回目の取り出しで failed_jobs に移り、記録が何も残らなかった）
        Mail::assertSent(ApplicationConfirmedMail::class, 5);
        Mail::assertNotSent(PostingCancelledMail::class);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(5, (int) DB::table('jobs')->sole()->attempts);
        $this->assertSame(0, DB::table('notification_logs')->where('kind', NotificationKind::PostingCancelled->value)->count()); // 戻された回は handle() に入らない

        // 枠が空いたら送られる
        Context::forget('company_id');
        $this->work();

        Mail::assertSent(PostingCancelledMail::class, 1);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(NotificationStatus::Sent->value, DB::table('notification_logs')->where('kind', NotificationKind::PostingCancelled->value)->sole()->status);
    }

    public function test_流量の上限で戻され続けて期限を過ぎたらnotification_logsにfailedの記録が作られる(): void
    {
        Mail::fake();
        config(['myteam.mails_per_minute' => 1, 'myteam.mail_retry_until_minutes' => 3]);
        $application = $this->cancelledApplication('hanako@example.com');
        SendPostingCancelledMail::dispatch($application->id)->onConnection('database');

        // 1 度も handle() に入らない（claim されない）まま、積んでから 3 分を過ぎる
        foreach (range(1, 4) as $_) {
            $this->sendOtherMailFirst();
            Context::forget('company_id');
            $this->work();
            $this->travel(70)->seconds();
        }

        Mail::assertNotSent(PostingCancelledMail::class);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertStringContainsString('MaxAttemptsExceededException', DB::table('failed_jobs')->sole()->exception);
        // claim の前に打ち切られても、宛先と対象が分かるので「届いていない」記録を作る（要件 7 章。F-24 の画面に出る）
        $log = DB::table('notification_logs')->where('kind', NotificationKind::PostingCancelled->value)->sole();
        $this->assertSame(NotificationStatus::Failed->value, $log->status);
        $this->assertSame('hanako@example.com', $log->recipient_email);
        $this->assertSame(['application', $application->id], [$log->subject_type, (int) $log->subject_id]);
        $this->assertSame('MaxAttemptsExceededException: 処理に失敗しました。', $log->error);
        $this->assertSame($this->company->id, (int) $log->company_id);
    }

    public function test_積んでから再試行の期限を過ぎたらfailedに入りnotification_logsがfailedになる(): void
    {
        config(['myteam.mail_retry_until_minutes' => 60]);
        $application = $this->cancelledApplication('hanako@example.com');
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused'));
        SendPostingCancelledMail::dispatch($application->id)->onConnection('database');

        // 1 回目は送信で例外（例外は 1 回だけなので maxExceptions=3 にはまだ届かない）
        Context::forget('company_id');
        $this->work();
        $this->assertSame(NotificationStatus::Pending->value, DB::table('notification_logs')->sole()->status);
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // 積んでから 60 分を過ぎて取り出されると、handle() に入らずに failed() へ
        $this->travel(61)->minutes();
        Context::forget('company_id');
        $this->work();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertStringContainsString('MaxAttemptsExceededException', DB::table('failed_jobs')->sole()->exception);
        $log = DB::table('notification_logs')->sole();
        $this->assertSame(NotificationStatus::Failed->value, $log->status);
        $this->assertSame('MaxAttemptsExceededException: 処理に失敗しました。', $log->error);
    }

    /**
     * 別の応募の確定通知をその場で（sync で）送り、mails リミッターの今の 1 分の枠を使う。RateLimited は sync でも効く。
     * ループの中で Context を消しているので、企業は runAs で明示する
     */
    private function sendOtherMailFirst(): void
    {
        CurrentCompany::runAs($this->company->id, function () {
            $other = Application::factory()->confirmed()->create();
            SendApplicationConfirmedMail::dispatchSync($other->id);
        });
    }

    private function cancelledApplication(string $email): Application
    {
        $posting = JobPosting::factory()->create(['status' => JobPostingStatus::Cancelled]);

        return Application::factory()->for($posting)->for(Worker::factory()->state(['email' => $email]))
            ->cancelled(CancelReason::Company)->create();
    }

    /**
     * backoff は [10, 60]。試行のたびに Context を消し、ワーカーがペイロードから戻すことも合わせて確かめる
     */
    private function failThreeTimes(): void
    {
        foreach ([0, 11, 61] as $seconds) {
            $this->travel($seconds)->seconds();
            Context::forget('company_id');
            $this->work();
        }
    }

    /**
     * ワーカーを 1 回だけ回す（make horizon の代わり）。--sleep=0 で取り出せるジョブがなくても待たずに終わる
     */
    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'mails', '--once' => true, '--sleep' => 0])->assertExitCode(0);
    }

    /**
     * array メーラーが受け取った（＝送れた）メール
     *
     * @return list<SentMessage>
     */
    private function sentMessages(): array
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all();
    }

    /**
     * Mail::shouldReceive() のモックを外し、本物の MailManager に戻す（MailFake は今のマネージャを包むので、モックのままだと fake できない）
     */
    private function stopFailingMailer(): void
    {
        Mail::clearResolvedInstance('mail.manager');
        $this->app->forgetInstance('mail.manager');
    }
}
