---
name: async-rules
description: myteam 第3弾の非同期処理の規約（Job / Queue / イベント / スケジューラ / バッチ / メール / 通知の記録 / キャッシュ）。ジョブの書き方の型、冪等性の守り方、失敗時の扱い、テストの型。P4 以降の implementer-api・tester・code-reviewer が従う
---

# 非同期処理の規約（P4 から）

設計の全体と理由は `docs/03_非同期処理の設計.md`。ここは「書くときに守る型」。

## ジョブの型（メールを送るジョブ）
```php
class SendApplicationConfirmedMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $applicationId)   // モデルではなく ID
    {
        $this->onQueue('mails');                              // キュー名を明示
    }

    public function backoff(): array
    {
        return [10, 60];                                      // 1 回目の再試行は 10 秒後、2 回目は 60 秒後
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping("application_confirmed:{$this->applicationId}")];  // 同じ通知を同時に 2 つ走らせない
    }

    public function handle(NotificationLogService $logs): void
    {
        $application = Application::with('worker', 'jobPosting.location')->find($this->applicationId);
        if ($application === null || $application->status !== ApplicationStatus::Confirmed) {
            return;                                           // 実行時点の最新状態を見る。もう確定でなければ送らない
        }

        $log = $logs->claim(NotificationKind::ApplicationConfirmed, $application->worker, $application, $application->worker->email);
        if ($log === null) {
            return;                                           // 送信済み（再実行・二重投入）
        }

        Mail::to($log->recipient_email)->send(new ApplicationConfirmedMail($application));
        $logs->markSent($log);
    }

    public function failed(?Throwable $e): void              // 全部の試行が失敗した後に 1 回だけ呼ばれる
    {
        app(NotificationLogService::class)->markFailedByKey("application_confirmed:application:{$this->applicationId}", $e);
    }
}
```

## 守ること
| 規約 | 理由 |
|---|---|
| コンストラクタには ID を渡す | モデルを渡すと積んだ時点の姿に引きずられる。実行時に読み直す |
| `handle()` の最初に「まだ送るべき状態か」を確かめる | 確定の直後にキャンセルされた応募に確定通知を送らない |
| 業務ルールをジョブに書かない | 定員・状態遷移はサービスにある。ジョブは読んで送るだけ |
| `claim → 送信 → markSent` の順 | 再実行しても 2 通目を送らない（冪等性） |
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
- `Cache::remember("dashboard:{$companyId}", now()->addMinutes(5), fn () => ...)`
- 破棄は `DashboardService::forget()` の 1 箇所。呼ぶのは `Application` と `JobPosting` に付けた Observer（`ShouldHandleEventsAfterCommit`）だけ
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
