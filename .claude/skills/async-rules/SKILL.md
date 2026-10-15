---
name: async-rules
description: myteam 第3弾の非同期処理の規約（Job / Queue / イベント / スケジューラ / バッチ / メール / 通知の記録 / キャッシュ）。ジョブの書き方の型、冪等性の守り方、失敗時の扱い、テストの型。ジョブ・キュー・通知・キャッシュに触れるときの implementer-api・tester・code-reviewer が従う
---

# 非同期処理の規約

設計の全体と理由は `docs/03_非同期処理の設計.md`。ここは「書くときに守る型」。

## ジョブの型（メールを送るジョブ）
```php
class SendApplicationConfirmedMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;                                    // 試行の回数では打ち切らない（キューへ戻された回を失敗に数えない）

    public int $maxExceptions = 3;                            // 例外が 3 回出たら failed

    public function __construct(public int $applicationId)   // モデルではなく ID
    {
        $this->onQueue('mails');                              // キュー名を明示
    }

    public function retryUntil(): DateTimeInterface           // 積んでから一定時間（既定 60 分）でもあきらめる
    {
        return now()->addMinutes((int) config('myteam.mail_retry_until_minutes'));
    }

    public function backoff(): array
    {
        return [10, 60];                                      // 1 回目の再試行は 10 秒後、2 回目は 60 秒後
    }

    public function middleware(): array
    {
        // RateLimited('mails'): 流量はメールサーバー全体の制約なので、メールのジョブはすべて同じリミッター。ロックより先に置く。
        // WithoutOverlapping: 同じ通知を同時に 2 つ走らせない。キーは dedupe_key と同じ（応募ID は全社の通し番号なので企業ID は不要）。
        // 重なった 2 本目は戻さず捨てる。ワーカーが落ちてロックが残っても 120 秒で外れる
        return [
            new RateLimited('mails'),
            (new WithoutOverlapping($this->dedupeKey()))->dontRelease()->expireAfter(120),
        ];
    }

    public function handle(NotificationLogService $logs): void
    {
        $application = $this->target();
        if ($application === null) {
            return;                                           // 実行時点の最新状態を見る。もう確定でなければ送らない
        }

        $log = $logs->claim(NotificationKind::ApplicationConfirmed, $application->worker, $application); // 宛先のアドレスは worker->email から決まる（P4-01 で決定）
        if ($log === null) {
            return;                                           // 送信済み（再実行・二重投入）
        }

        Mail::to($log->recipient_email)->send(new ApplicationConfirmedMail($application));
        $logs->markSent($log);
    }

    public function failed(?Throwable $e): void              // 例外 3 回か期限切れで打ち切った後に 1 回だけ呼ばれる
    {
        try {
            $logs = app(NotificationLogService::class);
            if ($logs->markFailedByKey($this->dedupeKey(), $e)) {
                return;                                       // claim 済みの行を failed にした
            }
            $application = $this->target();                   // claim の前に打ち切られた（流量で戻され続けて期限切れ等）
            if ($application !== null) {                      // まだ送るべき対象なら「届いていない」記録を作る
                $logs->recordFailure(NotificationKind::ApplicationConfirmed, $application->worker, $application, $e);
            }
        } catch (Throwable $recordError) {
            report($recordError);                             // failed() から例外を漏らさない
        }
    }

    private function target(): ?Application                  // 「送るべき対象か」の判定。handle() と failed() で共有
    {
        $application = Application::with('worker', 'jobPosting.location')->find($this->applicationId);

        return $application?->status === ApplicationStatus::Confirmed ? $application : null;
    }

    private function dedupeKey(): string                      // キーは NotificationLogService::dedupeKey() の 1 箇所で作る
    {
        $subject = new Application;                           // ID だけを持つ未保存のインスタンス（DB は読まない）
        $subject->setAttribute($subject->getKeyName(), $this->applicationId);

        return NotificationLogService::dedupeKey(NotificationKind::ApplicationConfirmed, $subject);
    }
}
```

## 守ること
| 規約 | 理由 |
|---|---|
| コンストラクタには ID を渡す | モデルを渡すと積んだ時点の姿に引きずられる。実行時に読み直す |
| `handle()` の最初に「まだ送るべき状態か」を確かめる | 確定の直後にキャンセルされた応募に確定通知を送らない |
| 業務ルールをジョブに書かない | 定員・状態遷移はサービスにある。ジョブは読んで送るだけ |
| メールのジョブは `$tries = 0` + `$maxExceptions = 3` + `retryUntil()`（`$tries = 3` にしない） | `RateLimited` などでキューへ戻された回も tries に数えるので、戻されるだけで failed になり、claim の前なので `notification_logs` に何も残らず「届いていない」が見えない（P4-03 で再現）。打ち切りは例外の回数と期限で行う |
| `failed()` は `markFailedByKey` → 行がなく `target()` が非 null なら `recordFailure`、全体を try / `report()` で包む | claim の前に打ち切られた通知も「届いていない」と記録に残す（P4-03 のレビュー）。failed() から例外を漏らさない |
| F-23 の仕上げのジョブ（`FinalizeReportExport`）は例外: 流量制限なし・`$tries = 3`、ロック（`WithoutOverlapping` + `dontRelease()`）は仕上げ全体（連結 + 通知）にかけ、期限は `retry_after` より短く（60 秒、`$timeout` 50） | 管理者が自分で依頼した 1 件ごとの 1 通で、流量で戻すと連結の再試行の回数を食う。期限が retry_after より長いと、落ちた実行の再実行がロックで捨てられる（P4-05） |
| Markdown に差し込む利用者の入力は `App\Mail\Concerns\FormatsMarkdownText` の `singleLine()`（本文）/ `tableCell()`（表） | `withSecuredEncoding()` は改行と `\|` を残す。見出しや表の列を作らせない |
| `claim → 送信 → markSent` の順 | 再実行しても 2 通目を送らない（冪等性） |
| `claim()` は 3 引数（種類・宛先・対象）。宛先のアドレスは渡さない | アドレスは宛先のモデルの `email` から決まる。呼び出し側が別のアドレスを渡せないようにする（P4-01） |
| `WithoutOverlapping` のキーは `NotificationLogService::dedupeKey()` から作り、`dontRelease()` と `expireAfter()` を必ず付ける | キーの書き方を 1 箇所に。戻すと重なった 2 本目が tries を食い、送れているのに failed が残る。`expireAfter` がないと落ちたワーカーのロックが残り続ける（P4-02） |
| ワーカー宛てのメールにリンクを付けない（ボタンも、既定のヘッダーの `APP_URL` も） | ワーカーはログインできない。管理画面の URL を外部に配らない。`x-mail::message` ではなく `x-mail::layout` にリンクのないヘッダーを渡す（P4-02） |
| Markdown メールは `Markdown::withSecuredEncoding()`（`AppServiceProvider`）。表のセルに入れる入力は `\|` と改行も潰す | 名前に `[x](https://...)` を入れるとリンクになる。secured encoding は `[` `<` `>` しかエスケープしないので、`\|` は列を壊す（P4-02） |
| `$tries` `backoff()` `failed()` を必ず書く | 既定のままだと 1 回の失敗で終わる／失敗が記録に残らない |
| キュー名を明示（`mails` / `default`） | Horizon でメールと重い処理を別々に観察・制御する |
| ジョブの中の DB 書き込みは短く。**メール送信をトランザクションの中でしない** | SQLite は書き込みが 1 本。長く持つと Web 側が待たされる |
| イベントは `ShouldDispatchAfterCommit` | ロールバックされた確定の通知を出さない |
| 積むのはリスナ。サービスは `event()` を呼ぶだけ | サービスが「メール」を知らなくて済む |
| 企業は `CurrentCompany`（Context が引き継ぐ）。ジョブの中で `auth()` を使わない | ジョブにはログインユーザーがいない |

## dedupe_key（`notification_logs` の UNIQUE）
| 通知 | キー |
|---|---|
| 確定通知 | `application_confirmed:application:{応募ID}` |
| 中止通知 | `posting_cancelled:application:{応募ID}` |
| 前日リマインド | `shift_reminder:application:{応募ID}` |
| 出力完了 | `export_ready:report_export:{出力ID}` |

`claim()` の動き: キーで行を探す → なければ `pending` で作って返す → あって `sent` なら `null` → あって `pending` / `failed` ならその行を返す（再送してよい）。

## スケジューラ（F-22）
- 処理は Artisan コマンド `app:send-reminders {--date=}` に書き、`routes/console.php` から `Schedule::command('app:send-reminders')->dailyAt(config('myteam.reminder_time'))->withoutOverlapping()` で呼ぶ
- コマンドは企業ごとに `CurrentCompany::runAs()` で回し、対象の応募ごとにジョブを積む。**コマンドの中でメールを送らない**
- ジョブは `ShouldBeUnique`（`uniqueId()` は応募ID）。コマンドを 2 回実行しても同じジョブが 2 つ積まれない

## バッチ（F-23）
- `Bus::batch([...拠点ごとのジョブ])->name("report-export:{$id}")->onQueue('default')->then(...)->catch(...)->dispatch()`
- バッチ内のジョブは `Batchable` を使い、先頭で `if ($this->batch()?->cancelled()) return;`
- `then` / `catch` のクロージャは直列化される。`$this` を掴まず、ID だけを `use` する
- 成果物は `Storage::disk('local')` に置く。パスは `exports/{company_id}/{export_id}/monthly-{month}.csv`。**入力からパスを組み立てない**（DB の `file_path` だけを使う）

## キャッシュ（F-19）
- `Cache::remember("dashboard:{$companyId}", $expiresAt, fn () => ...)`。`$expiresAt` は「設定の秒数後」と「翌日 0:00」の早い方（日付をまたいで前日の一覧を残さない）。`now` は 1 回だけ取り、集計の「今日」・有効期限・`generated_at` に同じ値を使う
- 破棄は `DashboardService::forget(int $companyId)` の 1 箇所。呼ぶのは `Application` / `JobPosting` / `Location` に付けた Observer（`ShouldHandleEventsAfterCommit`）だけで、消すのは変更されたモデルの `company_id` の企業
- 一括の `query()->update()` は Observer を通らない。対象のモデルに一括更新を足すときは破棄を考える
- 二重クリック対策など「同時に 1 つだけ」は `Cache::lock($key, 10)->block(3, fn () => ...)`

## テストの型
| 確かめること | 書き方 |
|---|---|
| 確定したらジョブが積まれる | `Queue::fake()` → 確定 API → `Queue::assertPushedOn('mails', SendApplicationConfirmedMail::class, fn ($j) => $j->applicationId === $id)` |
| 定員エラーで巻き戻ったら積まれない | `Queue::fake()` → 定員超過の確定 → `Queue::assertNothingPushed()` |
| 実行したらメールが 1 通出る | `Mail::fake()` → `(new Job($id))->handle(app(NotificationLogService::class))` → `Mail::assertSent(..., 1)` |
| 2 回実行しても 1 通（冪等性） | 上を 2 回呼んで `Mail::assertSentCount(1)` |
| 状態が変わっていたら送らない | キャンセルしてから `handle()` → `Mail::assertNothingSent()` |
| 失敗が記録される | `Mail::shouldReceive('to')->andThrow(...)` か失敗する Mailable → `failed()` を呼ぶ → `notification_logs.status = failed` |
| 再実行で送れる | 失敗させた後に `handle()` → `sent` になる |
| コマンドを 2 回実行しても二重に送らない | `$this->artisan('app:send-reminders', ['--date' => ...])` を 2 回 → 送信 1 通 |
| バッチが積まれる | `Bus::fake()` → `POST /api/report-exports` が 202 → `Bus::assertBatched(fn ($b) => $b->jobs->count() === 拠点数)` |
| 署名付きURL | 正しい URL は 200、`expires` を過ぎたら 403（`$this->travel(31)->minutes()`）、`signature` を変えたら 403 |
| キャッシュの破棄 | ダッシュボード取得 → 確定 → もう一度取得 → 値が変わっている |

`phpunit.xml` は `QUEUE_CONNECTION=sync` `CACHE_STORE=array` `MAIL_MAILER=array` なので、Redis と Mailpit がなくてもテストは通る。**実物（Redis・Horizon・Mailpit）での確認は README の手動チェックリストで行う。**

## 参考（日本語）
- キュー: https://readouble.com/laravel/13.x/ja/queues.html
- Horizon: https://readouble.com/laravel/13.x/ja/horizon.html
- メール: https://readouble.com/laravel/13.x/ja/mail.html
- 通知: https://readouble.com/laravel/13.x/ja/notifications.html
- タスクスケジュール: https://readouble.com/laravel/13.x/ja/scheduling.html
- キャッシュ（ロック）: https://readouble.com/laravel/13.x/ja/cache.html
- ファイルストレージ: https://readouble.com/laravel/13.x/ja/filesystem.html
- 署名付きURL: https://readouble.com/laravel/13.x/ja/urls.html
