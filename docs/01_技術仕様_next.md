# 技術仕様（契約）— 第3弾 `next_team`

作成日: 2026-10-01 / ステータス: **P0 時点の契約**。実装でずれたら、実装ではなくまずこの文書を直す（ずれた理由も書く）。

- 要件の正: [`../../00_要件定義.md`](../../00_要件定義.md)
- 決定の経緯: [`00_計画と決定事項.md`](00_計画と決定事項.md)
- 非同期の詳細: [`03_非同期処理の設計.md`](03_非同期処理の設計.md)

この文書は「担当が互いのコードを読まなくても結合できる」ための契約です。**api 担当と web 担当の境界は 6 章（API 契約）だけ**です。web 担当は `api/` のコードを読まず、6 章と実際のレスポンスだけを見て作ります。

第2弾 `ecc_team` は README とテーブル構造だけを参考にし、コードは見ていません（決定14）。

---

## 1. 構成

| 項目 | 内容 |
|---|---|
| `api/` | Laravel 13 / PHP 8.4。**JSON API 専用**（Blade の画面は持たない） |
| `web/` | Next.js 16（App Router）/ React 19 / TypeScript / Tailwind CSS 4 |
| DB | SQLite（`api/database/database.sqlite`。テストは in-memory） |
| キュー・キャッシュ | Redis（Docker。P4 から） |
| メール | Mailpit（Docker。P4 から） |
| 認証 | Sanctum SPA 認証（Cookie + CSRF）＋ Fortify ヘッドレス |

### ポート

| 用途 | URL | 備考 |
|---|---|---|
| API | http://localhost:8002 | 8000 は第1弾、8001 は第2弾が使う |
| 画面 | http://localhost:3002 | 3000 は別プロジェクトの Docker が使っている |
| Mailpit | http://localhost:8025 | SMTP は 1025 |
| Redis | localhost:6379 | |
| Horizon | http://localhost:8002/horizon | P4 から。ログイン済み管理者のみ |
| Telescope | http://localhost:8002/telescope | P4 から。ローカルのみ |

### 使うパッケージ（これ以外を入れるときは人に確認する）

| 場所 | パッケージ | いつ | 用途 |
|---|---|---|---|
| api | `laravel/sanctum` | P1 | SPA 認証（`php artisan install:api` で入る） |
| api | `laravel/fortify` | P1 | ログイン・ログアウト・スロットル |
| api | `laravel-lang/common` | P1 | バリデーションメッセージの日本語 |
| api | `laravel/horizon` | P4 | キューワーカーと監視画面 |
| api | `laravel/telescope`（dev） | P4 | リクエスト・クエリ・ジョブ・メールの観察 |
| api | `laravel/reverb` | P6（Could） | WebSocket サーバ |
| web | `zod` | P1 | API レスポンスの検証と型の導出 |
| web | `prettier` `prettier-plugin-tailwindcss`（dev） | 導入済み | 整形 |
| web | `laravel-echo` `pusher-js` | P6（Could） | WebSocket クライアント |

UI コンポーネントライブラリ、状態管理ライブラリ、データ取得ライブラリ（SWR / TanStack Query）は入れません（決定8）。

---

## 2. 環境変数

`.env` はフックが編集を止めます。エージェントは `.env.example` を直し、人が `.env` に反映します。**P1〜P3 の値は 2026-10-01 に反映済み**です。P4 の値は P4 の開始時に人が反映します。

### `api/.env`

| キー | 値 | いつ | 理由 |
|---|---|---|---|
| `APP_NAME` | `next_team` | P1 | セッション Cookie 名が `next-team-session` になり（`Str::slug(APP_NAME)` なのでハイフン区切り。P1-03 で実物を確認）、他の弾（同じ `localhost`）と混ざらない |
| `APP_URL` | `http://localhost:8002` | P1 | 署名付きURL の生成に使われる |
| `FRONTEND_URL` | `http://localhost:3002` | P1 | CORS の許可オリジン、メール本文のリンク先 |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `ja` / `en` | P1 | エラーメッセージを日本語に |
| `APP_FAKER_LOCALE` | `ja_JP` | P1 | シードの名前を日本語に（**テストは `phpunit.xml` で `en_US` に固定**。第2弾の教訓） |
| `APP_TIMEZONE` | `Asia/Tokyo` | P1 | `config/app.php` の `timezone` を `env('APP_TIMEZONE', 'Asia/Tokyo')` にする |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:3002` | P1 | このオリジンからのリクエストだけセッション認証を有効にする |
| `SESSION_DOMAIN` | `localhost` | P1 | Cookie をポート違いの画面と共有する |
| `MYTEAM_MIN_WAGE` | `1226` | P2 | F-05 の最低賃金（`config/myteam.php`） |
| `QUEUE_CONNECTION` | `redis` | **P4** | それまでは `database` のまま（何も積まない） |
| `CACHE_STORE` | `redis` | **P4** | それまでは `database` |
| `MAIL_MAILER` / `MAIL_HOST` / `MAIL_PORT` | `smtp` / `127.0.0.1` / `1025` | **P4** | Mailpit |
| `MAIL_FROM_ADDRESS` | `no-reply@myteam.example` | **P4** | |
| `MYTEAM_REMINDER_TIME` | `18:00` | **P4** | F-22 の送信時刻 |
| `MYTEAM_MAILS_PER_MINUTE` | `60` | P4（任意） | メールの流量（全社・全種類で 1 つの枠） |
| `MYTEAM_MAIL_RETRY_UNTIL_MINUTES` | `60` | P4（任意） | メールのジョブを打ち切るまでの時間 |
| `MYTEAM_REPORT_EXPORT_STALE_MINUTES` | `30` | P4（任意） | 生成中のまま止まった出力を失敗にするまでの時間（下限 5） |
| `MYTEAM_DASHBOARD_CACHE_SECONDS` | `300` | P4（任意） | ダッシュボードのキャッシュの秒数（0 以下でキャッシュしない） |
| `CACHE_PREFIX` / `REDIS_PREFIX` / `HORIZON_PREFIX` | `next-team-cache-` / `next-team-database-` / `next-team-horizon:` | **P4** | 同じ Redis を他の弾と共有したときのキーの衝突を防ぐ |

### `web/.env.local`

| キー | 値 | 使う場所 |
|---|---|---|
| `API_URL` | `http://localhost:8002` | サーバ側（Server Component / Server Action / Route Handler） |
| `NEXT_PUBLIC_API_URL` | `http://localhost:8002` | ブラウザ側（ログイン・ログアウトだけ） |
| `WEB_ORIGIN` | `http://localhost:3002` | サーバ側 fetch に付ける `Origin` / `Referer` |

---

## 3. テーブル定義

全テーブルに `id`（bigint PK）と `created_at` / `updated_at` があります。金額は円の整数、時間は分の整数です。

### 3.1 業務テーブル（P2 で作り、基盤確定後にロック）

**companies**

| 列 | 型 | 制約 |
|---|---|---|
| name | string | NOT NULL |

**users**（管理者）

| 列 | 型 | 制約 |
|---|---|---|
| company_id | FK → companies | NOT NULL、restrictOnDelete |
| name | string | NOT NULL |
| email | string | NOT NULL、UNIQUE |
| password | string | NOT NULL（hashed cast） |
| remember_token | string | NULL |

Fortify の 2FA 用の列は作りません（`features` が空なので使わない）。

**locations**（拠点）

| 列 | 型 | 制約 |
|---|---|---|
| company_id | FK | NOT NULL、INDEX |
| name | string | NOT NULL |
| address | string | NULL |

**workers**（ワーカー）

| 列 | 型 | 制約 |
|---|---|---|
| company_id | FK | NOT NULL |
| name | string | NOT NULL |
| email | string | **NOT NULL**（第3弾から必須。通知の宛先） |
| phone | string | NULL |
| is_active | boolean | NOT NULL、default true |

INDEX: `(company_id, is_active)`、`(company_id, name)`。メールの一意制約は要件にないので付けません。

**job_postings**（募集）

| 列 | 型 | 制約 |
|---|---|---|
| company_id | FK | NOT NULL |
| location_id | FK → locations | NOT NULL、**restrictOnDelete**（F-01: 使われている拠点は消せない） |
| job_title | string | NOT NULL |
| work_date | date | NOT NULL |
| start_time | time | NOT NULL |
| end_time | time | NOT NULL |
| capacity | unsigned int | NOT NULL（1 以上は検証で守る） |
| hourly_wage | unsigned int | NOT NULL |
| transport_allowance | unsigned int | NOT NULL、default 0 |
| status | string | NOT NULL、default `open`（`open` / `closed` / `cancelled`） |

INDEX: `(company_id, work_date)`、`(company_id, status)`、`(location_id)`。

**applications**（応募）

| 列 | 型 | 制約 |
|---|---|---|
| company_id | FK | NOT NULL |
| job_posting_id | FK → job_postings | NOT NULL、restrictOnDelete |
| worker_id | FK → workers | NOT NULL、restrictOnDelete |
| status | string | NOT NULL、default `applied`（`applied` / `confirmed` / `rejected` / `cancelled`） |
| confirmed_at | datetime | NULL |
| confirmed_hourly_wage | unsigned int | NULL（確定時に募集の値をコピーして固定） |
| confirmed_transport_allowance | unsigned int | NULL（同上） |
| cancel_reason | string | NULL（`company` / `worker`） |
| cancelled_at | datetime | NULL |

**UNIQUE `(job_posting_id, worker_id)`**（F-09: 二重応募の禁止を DB でも守る）。INDEX: `(job_posting_id, status)`、`(company_id, status)`、`(worker_id)`（SQLite は FK に索引を作らないので、ワーカー側からの参照と restrictOnDelete の検査用。P2-01 の database-reviewer で決定）。

**attendances**（勤怠）

| 列 | 型 | 制約 |
|---|---|---|
| company_id | FK | NOT NULL |
| application_id | FK → applications | NOT NULL、**UNIQUE**（1 対 1） |
| clock_in | datetime | NOT NULL |
| clock_out | datetime | NOT NULL（深夜跨ぎは翌日の日付になる） |
| break_minutes | unsigned int | NOT NULL、default 0 |
| worked_minutes | unsigned int | NOT NULL（保存時に確定） |
| wage_pay | unsigned int | NOT NULL（同上。交通費を含まない） |
| total_pay | unsigned int | NOT NULL（同上。交通費を含む） |

INDEX: `(company_id, clock_in)`（勤怠の日時での参照用。月次集計の絞り込みは 4.6 のとおり `job_postings (company_id, work_date)` が効く）。

`work_date` はモデル経由でも `Y-m-d`（時刻なし）で保存する。SQLite は文字列で比べるので、書式が混ざると半開区間や等号の比較から漏れる（P2-01 の database-reviewer の指摘）。

### 3.2 非同期用のテーブル（P4・P5 で足す。新しいマイグレーションファイルとして追加）

**notification_logs**（通知の記録。F-20〜F-24 の冪等性と履歴）

| 列 | 型 | 制約 |
|---|---|---|
| company_id | FK | NOT NULL |
| kind | string | NOT NULL（`application_confirmed` / `posting_cancelled` / `shift_reminder` / `export_ready`） |
| recipient_type / recipient_id | morphs | NOT NULL（`worker` または `user`） |
| subject_type / subject_id | morphs | NOT NULL（`application` / `job_posting` / `report_export`） |
| recipient_email | string | NOT NULL（送信時点の宛先を残す） |
| dedupe_key | string | NOT NULL、**UNIQUE**（例: `shift_reminder:application:15`） |
| status | string | NOT NULL、default `pending`（`pending` / `sent` / `failed`） |
| sent_at | datetime | NULL |
| failed_at | datetime | NULL |
| error | text | NULL |

INDEX: `(company_id, created_at)`。`*_type` には morph map の短い名前が入ります（クラス名は入れない）。

（P4-01 で決定）`dedupe_key` は `{kind}:{subject_type}:{subject_id}`（`NotificationLogService::dedupeKey()` の 1 箇所）で、UNIQUE は全社で一意（ID は全社の通し番号なので衝突しない。企業ごとにすると、企業を取り違えたジョブが同じ相手に 2 通目を送れてしまう）。**中止通知の subject は応募**（宛先ごとに 1 通にするため。募集を subject にすると 2 人目以降が潰れる）。`claim()` は宛先と対象が今の企業のものでなければ LogicException。`error` 列には例外のクラス名と固定の日本語だけを残し、生のメッセージは failed_jobs と Log に任せる（SQL やメールサーバーの認証情報を画面に出さない）。

**同時実行について（P4-01 で決定。人の確認待ち）**: UNIQUE が保証するのは「一度送った後の再実行・再投入で 2 通目を送らない」こと。同じ通知のジョブが同時に 2 つ走るのを止めるのは `WithoutOverlapping`（キャッシュのロック）で、保証は at-least-once の範囲。DB で厳密に止めたいなら `claimed_at` 列を足して条件付き UPDATE で取る案（新しいマイグレーションで後から足せる）。

**report_exports**（集計出力の依頼。F-23）

| 列 | 型 | 制約 |
|---|---|---|
| company_id | FK | NOT NULL |
| user_id | FK → users | NOT NULL（依頼した管理者） |
| month | char(7) | NOT NULL（`YYYY-MM`） |
| status | string | NOT NULL、default `queued`（`queued` / `processing` / `completed` / `failed`） |
| batch_id | string | NULL（`job_batches.id`） |
| file_path | string | NULL（`Storage::disk('local')` 上の相対パス） |
| row_count | unsigned int | NULL |
| completed_at | datetime | NULL |
| failed_at | datetime | NULL |

### 3.3 フレームワークのテーブル

| テーブル | 用途 | 備考 |
|---|---|---|
| `sessions` | セッション（`SESSION_DRIVER=database`） | 雛形のまま |
| `cache` / `cache_locks` | P3 まで使う。P4 から Redis | 雛形のまま |
| `jobs` | 使わない（キューは Redis） | 雛形のまま残す |
| `job_batches` | `Bus::batch()` の進捗（F-23） | Redis キューでも DB に持つ |
| `failed_jobs` | 失敗ジョブの記録 | Redis キューでも DB に持つ |
| `notifications` | 管理者向けアプリ内通知（database チャネル） | P4 で `php artisan make:notifications-table` |
| `personal_access_tokens` | Sanctum の API トークン | `install:api` で作られるが**使わない**（SPA 認証は Cookie） |
| `telescope_entries` ほか | Telescope | P4 |

### 3.4 SQLite の設定（`api/config/database.php`）

| キー | 値 | 理由 |
|---|---|---|
| `journal_mode` | `WAL` | Web とキューワーカーが同時に読み書きする（P4）。WAL なら読み取りが書き込みを待たない |
| `busy_timeout` | `5000` | ロック待ちを即エラーにせず 5 秒待つ |
| `transaction_mode` | `IMMEDIATE` | トランザクション開始時に書き込みロックを取る。**定員チェックの一貫性をこれで保証する**（12 章） |
| `foreign_key_constraints` | `true` | 既定のまま |

---

## 4. ドメイン（`api/app/`）

### 4.1 Enum（`app/Enums/`）

すべて string の backed enum で、`label(): string`（日本語）を持ちます。

| Enum | 値 → ラベル |
|---|---|
| `JobPostingStatus` | `open` 募集中 / `closed` 締切 / `cancelled` 中止 |
| `ApplicationStatus` | `applied` 応募中 / `confirmed` 確定 / `rejected` 却下 / `cancelled` キャンセル |
| `CancelReason` | `company` 企業都合 / `worker` ワーカー都合 |
| `NotificationKind`（P4） | `application_confirmed` 確定通知 / `posting_cancelled` 中止通知 / `shift_reminder` 前日リマインド / `export_ready` 出力完了 |
| `NotificationStatus`（P4） | `pending` 送信待ち / `sent` 送信済み / `failed` 失敗 |
| `ReportExportStatus`（P4） | `queued` 受付 / `processing` 生成中 / `completed` 完了 / `failed` 失敗 |

遷移（P4-01 で決定）: `NotificationStatus` は `pending → sent / failed`、`failed → sent / failed`（再送）、`sent` は終端。`ReportExportStatus` は `queued → processing / completed / failed`、`processing → completed / failed`、`completed` と `failed` は終端（`isInProgress()` は queued と processing）。集計出力の「再実行」は、失敗した依頼を直すのではなく同じ月の新しい依頼を出す（生成中でなければ受け付ける）。

状態遷移（F-14）は `ApplicationStatus::canTransitionTo(self $next): bool` の **1 箇所** に置きます。

```
applied   → confirmed / rejected
confirmed → cancelled
rejected / cancelled → （終端）
```

`JobPostingStatus::canTransitionTo()` も同じ形です: `open → closed / cancelled`、`closed → cancelled`。

### 4.2 テナント分離（`app/Support/CurrentCompany.php`、`app/Models/Concerns/BelongsToCompany.php`）

「今どの企業として動いているか」を 1 箇所で持ちます。Rails の `Current.company` に当たります。

```php
final class CurrentCompany
{
    public static function set(int $companyId): void;      // Context::add('company_id', ...)
    public static function id(): int;                       // 未設定なら LogicException（黙って全件を返さない）
    public static function check(): bool;
    public static function runAs(int $companyId, Closure $callback): mixed;  // 一時的に切り替えて戻す
}
```

- 中身は Laravel の `Context` ファサードです。**Context はキューに積んだジョブへ自動で引き継がれる** ので、リクエスト中に積んだジョブは同じ企業として動きます
- 設定する場所は 3 つだけです
  1. HTTP: ミドルウェア `SetCurrentCompany`（`auth:sanctum` の後ろ）がログイン中の管理者の `company_id` を入れる
  2. スケジューラ: コマンドが企業ごとに `CurrentCompany::runAs()` で回す（F-22）
  3. テスト: `TestCase::actingAsAdmin()` と、サービスを直接呼ぶテストの `setUp`
- `BelongsToCompany` トレイト（`Location` / `Worker` / `JobPosting` / `Application` / `Attendance` / `NotificationLog` / `ReportExport`）
  - グローバルスコープ `company`: `where company_id = CurrentCompany::id()`
  - `creating`: `company_id` を `CurrentCompany::id()` で埋める
- 他社のIDを URL に入れるとルートモデルバインディングが見つけられず **404** になります。Policy は作りません（第2弾で学習済み。状態による可否は業務ルールとしてサービスが 422 を返す）
- `withoutGlobalScopes()` を使ってよいのは、企業が決まる前に行を読む必要がある場所だけです（署名付きURL のダウンロード）。スケジューラの企業一覧は `Company` にスコープがないので `withoutGlobalScopes()` は不要（P4-04 で確認）。**使う行に理由コメントを必ず書く**

### 4.3 モデル（`app/Models/`）

- `#[Fillable([...])]` 属性を使い、**`company_id` と `status` は入れない**
- `Application::transitionTo(ApplicationStatus $next, array $attributes = []): void` が `status` を変える唯一の入口。遷移できなければ `DomainException`。`JobPosting::transitionTo()` も同じ
- `Application` の `$attributes = ['status' => 'applied']`、`JobPosting` の `$attributes = ['status' => 'open']`（`create()` 直後のメモリ上の値を DB の既定値と揃える。第2弾の教訓）
- cast は `casts()` メソッド。日付は `immutable_date` / `immutable_datetime`
- morph map を `AppServiceProvider::boot()` で強制する（P4）:
  `Relation::enforceMorphMap(['worker' => Worker::class, 'user' => User::class, 'application' => Application::class, 'job_posting' => JobPosting::class, 'report_export' => ReportExport::class])`

### 4.4 例外（`app/Exceptions/DomainException.php`）

業務ルール違反はすべて `App\Exceptions\DomainException`（メッセージは日本語、画面にそのまま出る）。`bootstrap/app.php` で **422** `{"message": "...", "errors": {}}` に変換します。入力欄に紐づけたいときは `new DomainException('...', field: 'worker_id')` とし、`errors.worker_id` に入れます。

### 4.5 サービス契約（`app/Services/`）

コントローラは「FormRequest で検証 → サービスを呼ぶ → Resource を返す」だけです。

| サービス | メソッド | 戻り値 | 投げる `DomainException` |
|---|---|---|---|
| `LocationService` | `delete(Location $location): void` | | 募集に使われている拠点は削除できません。 |
| `JobPostingService` | `update(JobPosting $p, array $data): JobPosting` | 更新後 | 中止した募集は編集できません。／募集人数は確定数（N名）より少なくできません。 |
| | `close(JobPosting $p): JobPosting` | | 遷移不可 |
| | `cancel(JobPosting $p): JobPosting` | | 遷移不可。**確定は企業都合キャンセルに、応募中は却下に自動遷移**。コミット後に `JobPostingCancelled` を発火 |
| | `duplicate(JobPosting $p, CarbonImmutable $workDate): JobPosting` | 新しい募集（`open`） | |
| `ApplicationService` | `apply(JobPosting $p, Worker $w): Application` | | 募集中でない／無効ワーカー／二重応募（`field: worker_id`） |
| | `confirm(Application $a): Application` | | 定員に達しているため承認できません。／遷移不可。コミット後に `ApplicationConfirmed` を発火 |
| | `reject(Application $a): Application` | | 遷移不可 |
| | `cancel(Application $a, CancelReason $reason): Application` | | 遷移不可（確定済みのみ） |
| `AttendanceService` | `save(Application $a, string $clockIn, string $clockOut, int $breakMinutes): Attendance` | 作成または更新 | 確定済みの応募にだけ勤怠を入力できます。／出勤と退勤が同じ時刻です。（`field: clock_out`）／休憩が労働時間を超えています。（`field: break_minutes`） |
| `PayrollCalculator` | `calculate(int $workedMinutes, int $hourlyWage, int $transport): PayrollResult` | `PayrollResult{workedMinutes, wagePay, totalPay}`（readonly） | |
| `MonthlyReportService` | `build(string $month): MonthlyReport` | `MonthlyReport{month, byWorker[], byLocation[], totalShifts, totalWorkedMinutes, totalPay}` | |
| | `csvRows(string $month, ?int $locationId = null): iterable` | CSV の行（ヘッダなし） | |
| `DashboardService` | `summary(): array` | 6 章の形 | |
| | `forget(int $companyId): void`（呼ぶのは Observer だけ。キャッシュの有効期限は「設定の秒数後」と「翌日 0:00」の早い方。キーは `dashboard:{company_id}` のままで、日付またぎで前日の一覧が残らない。P4-07 で決定） | | |
| `ReportExportService`（P4） | `request(User $u, string $month): ReportExport` | 受け付けた行（読み直した後の状態。Redis では `processing`、拠点 0 件は `queued`、積むのに失敗したら `failed`、sync のテストでは `completed` のこともある。P4-05 で決定） | 同じ月の出力が生成中です。 |
| `NotificationLogService`（P4） | `claim(NotificationKind $k, Model $recipient, Model $subject): ?NotificationLog`（宛先のアドレスは $recipient->email から決める。宛先・対象が未保存・company_id なし・他社なら LogicException。P4-01 で決定） | 送ってよいなら行、送信済みなら `null` | |
| | `markSent(NotificationLog $l): void` / `markFailed(NotificationLog $l, ?Throwable $e): void`（送信済みなら何もしない） | | |
| | `markFailedByKey(string $dedupeKey, ?Throwable $e): void`（ジョブの `failed()` 用。行がない・送信済みなら何もしない）/ `static dedupeKey(NotificationKind, Model $subject): string` | | |

### 4.6 業務ルールの決定（要件の解釈）

| 論点 | 決定 |
|---|---|
| 定員（F-11） | 承認はトランザクション内で確定数を数え、`確定数 >= capacity` なら拒否。キャンセルで 1 枠空く。却下は定員に影響しない |
| 時給の固定（6 章） | 確定時に `confirmed_hourly_wage` / `confirmed_transport_allowance` へコピー。報酬計算はこの値だけを見る |
| 締切と中止（F-07） | 締切 = 新規応募の受付停止。**締切後も応募中の応募は承認できる**。中止 = 確定は企業都合キャンセルに、応募中は却下に |
| 削除（7 章） | 物理削除は「募集に使われていない拠点」だけ |
| 報酬（F-16） | 労働分 = 退勤 − 出勤 − 休憩。賃金 = `intdiv(労働分 × 時給 + 59, 60)`（円未満切り上げ）。合計 = 賃金 + 交通費 |
| 深夜跨ぎ（F-15） | 入力は `HH:MM`。退勤 ≤ 出勤なら翌日扱い、同時刻はエラー。判定は `AttendanceService` の 1 箇所 |
| 月の範囲（F-17） | 勤務日（`job_postings.work_date`）が対象月に入る勤怠を集計。範囲は**半開区間**（`>= 月初 AND < 翌月初`）。`whereBetween` と `whereDate` は使わない |
| 集計の方法 | （実測 P2-06: 統計なしの SQLite では起点が `applications (company_id, status)`、そこから `attendances.application_id` の UNIQUE と各表の主キーでたどる。全表走査はない。`job_postings (company_id, work_date)` は統計がないと使われなかった）SQL の join + GROUP BY + SUM。`attendances` の保存済みの値を合計する。join 先にはグローバルスコープが効かないので `company_id` 条件を明示する |
| CSV（F-18 / F-23） | 列: ワーカー名, 拠点名, 勤務日, 労働分, 賃金, 交通費, 合計。UTF-8（BOM つき）。`=` `+` `-` `@` タブ CR で始まるセルは先頭に `'` を付ける。行の並びは同期（F-18）が勤務日 → 拠点、非同期（F-23）は拠点ごとのチャンクを連結するので拠点 → 勤務日（行の集合は一致。P4-05 で決定）（タブと CR は OWASP の推奨で P2-06 に追加。全角記号は Excel が数式にしないので対象外）。`shifts` は「確定の応募のうち勤怠が入力済みのもの」の件数 |
| 最低賃金（F-05） | `config('myteam.min_wage')` 以上 |
| 前日リマインド（F-22） | 「同じ勤務」は応募（1 枠）と読む。dedupe_key は `shift_reminder:application:{応募ID}` で勤務日を含まないので、送った後に勤務日が変わっても新しい日付では送らない。設定時刻（既定 18:00）の後に確定した翌日の勤務にはリマインドが届かない（確定通知は届く）。勤務日が過ぎたジョブは送らない（P4-04 で決定。人の確認待ち） |
| 無効ワーカー（F-03） | 新規応募できない。既存の確定・応募は影響を受けない |

---

## 5. 認証（Sanctum SPA + Fortify ヘッドレス）

### 5.1 サーバ側

| 設定 | 内容 |
|---|---|
| `config/fortify.php` | `'views' => false`、`'features' => []`、`'limiters' => ['login' => 'login']` |
| `FortifyServiceProvider` | `RateLimiter::for('login', ...)`: メール + IP で 1 分 5 回。画面の登録はしない |
| `bootstrap/app.php` | `$middleware->statefulApi()`、`api:` に `routes/api.php`、ミドルウェア別名 `company` => `SetCurrentCompany` |
| `config/cors.php` | `paths`: `api/*` `login` `logout` `sanctum/csrf-cookie`。`allowed_origins`: `[env('FRONTEND_URL')]`。`supports_credentials`: `true` |
| `config/sanctum.php` | `stateful` は `SANCTUM_STATEFUL_DOMAINS` から |

| メソッド | パス | 提供元 | 成功 | 失敗 |
|---|---|---|---|---|
| GET | `/sanctum/csrf-cookie` | Sanctum | 204（`XSRF-TOKEN` Cookie を発行） | |
| POST | `/login` | Fortify | 200 `{"two_factor": false}` | 422 `errors.email`、6 回目から 429 |
| POST | `/logout` | Fortify | 204 | |
| GET | `/api/user` | 自前 | 200 `{"data": {id, name, email, company: {id, name}}}` | 401 |

未ログインで `/api/*` を叩くと **401** `{"message": "Unauthenticated."}` が返ります。リダイレクトはしません。

### 5.2 クライアント側の流れ

```mermaid
sequenceDiagram
    participant B as ブラウザ
    participant N as Next サーバ :3002
    participant L as Laravel :8002

    Note over B,L: ログイン（ブラウザから直接。Cookie を受け取る操作だから）
    B->>L: GET /sanctum/csrf-cookie（credentials: include）
    L-->>B: Set-Cookie: XSRF-TOKEN, next-team-session
    B->>L: POST /login + X-XSRF-TOKEN
    L-->>B: 200 + Set-Cookie: next-team-session（再発行）
    B->>N: /dashboard へ遷移（Cookie は localhost 共通なので付く）

    Note over B,L: 表示（Server Component。Cookie は自動では付かないので転送する）
    N->>L: GET /api/dashboard + Cookie + Origin
    L-->>N: 200 JSON
    N-->>B: HTML

    Note over B,L: 更新（Server Action）
    B->>N: フォーム送信
    N->>L: POST /api/... + Cookie + Origin + X-XSRF-TOKEN
    L-->>N: 201 / 422
    N-->>B: 再描画 or エラー表示
```

- ログインとログアウトだけはブラウザから Laravel を直接叩きます（`web/lib/browser-api.ts`）。Cookie を受け取る／消す操作だからです
- それ以外はすべて Next サーバから叩きます（`web/lib/api.ts`）。ブラウザは Laravel を直接叩きません
- サーバ側 fetch には `Cookie`（転送）、`Origin` と `Referer`（`WEB_ORIGIN`。Sanctum が「stateful なリクエスト」と判定するのに必要）、更新系には `X-XSRF-TOKEN`（`XSRF-TOKEN` Cookie を URL デコードした値）を付けます

---

## 6. API 契約（api 担当と web 担当の境界）

### 6.1 共通

| 項目 | 形式 |
|---|---|
| 成功（単体） | `{"data": {...}}` |
| 成功（一覧・ページあり） | `{"data": [...], "meta": {"current_page", "last_page", "per_page", "total"}, "links": {...}}` |
| 成功（一覧・ページなし） | `{"data": [...]}` |
| エラー | `{"message": "日本語の理由", "errors": {"項目名": ["..."]}}`（`errors` は空オブジェクトのことがある） |
| 日付 | `"2026-09-20"` |
| 時刻 | `"09:00"` |
| 日時 | `"2026-09-20T09:00:00+09:00"`（ISO 8601） |
| 金額・分 | 整数 |
| 状態などの Enum | `{"value": "confirmed", "label": "確定"}`。**ラベルは API が返す**。色の対応だけ `web/lib/status.ts` に置く |
| ページ指定 | `?page=1&per_page=20`（`per_page` は最大 100） |

| ステータス | 意味 |
|---|---|
| 200 / 201 / 204 | 成功 / 作成 / 本文なし |
| 202 | 受付だけ完了（F-23） |
| 401 | 未ログイン → 画面は `/login` へ |
| 404 | 存在しない、または他社のデータ |
| 422 | 入力エラー、または業務ルール違反 |
| 429 | ログインの試行回数超過 |

すべての `/api/*` は `auth:sanctum` と `company` ミドルウェアの中にあります（6.9 のダウンロードだけ例外）。

### 6.2 拠点（F-01）

| メソッド | パス | 入力 | 成功 |
|---|---|---|---|
| GET | `/api/locations` | `page` `per_page` | 一覧 |
| POST | `/api/locations` | `name`（必須）`address` | 201 |
| GET | `/api/locations/{id}` | | 200 |
| PUT | `/api/locations/{id}` | 同上 | 200 |
| DELETE | `/api/locations/{id}` | | 204。使われていれば 422 |

`LocationResource`: `{id, name, address, job_postings_count, deletable}`

### 6.3 ワーカー（F-02〜F-04）

| メソッド | パス | 入力 | 成功 |
|---|---|---|---|
| GET | `/api/workers` | `q`（氏名の部分一致）`is_active` `page` `per_page` | 一覧 |
| POST | `/api/workers` | `name`（必須）`email`（必須・メール形式）`phone` | 201 |
| GET | `/api/workers/{id}` | | 200 |
| PUT | `/api/workers/{id}` | 同上 | 200 |
| PATCH | `/api/workers/{id}/active` | `is_active`（boolean） | 200 |

`WorkerResource`: `{id, name, email, phone, is_active}`

一覧の絞り込み: `q` は前後の空白を除いて氏名の部分一致（`%` `_` も文字として扱う。空白だけなら絞り込まない）。`is_active` は `1` / `0` / `true` / `false` のいずれか（空・未指定なら絞り込まない。それ以外は 422）。登録・更新（POST / PUT）では `is_active` を受け付けず、切り替えは PATCH だけ（P2-02 で決定）。

### 6.4 募集（F-05〜F-08）

| メソッド | パス | 入力 | 成功 |
|---|---|---|---|
| GET | `/api/job-postings` | `date_from` `date_to` `location_id` `status` `page` `per_page` | 一覧（勤務日の昇順） |
| POST | `/api/job-postings` | `location_id` `job_title` `work_date` `start_time` `end_time` `capacity` `hourly_wage` `transport_allowance` | 201 |
| GET | `/api/job-postings/{id}` | | 200 |
| PUT | `/api/job-postings/{id}` | 同上 | 200。中止済みなどは 422 |
| POST | `/api/job-postings/{id}/close` | | 200 |
| POST | `/api/job-postings/{id}/cancel` | | 200 |
| POST | `/api/job-postings/{id}/duplicate` | `work_date` | 201（新しい募集） |
| GET | `/api/job-postings/{id}/applicable-workers` | | 一覧（ページなし）。**有効で、まだ応募していないワーカー** `{id, name}` |

`JobPostingResource`: `{id, location: {id, name}, job_title, work_date, start_time, end_time, capacity, hourly_wage, transport_allowance, status: {value, label}, applications_count, confirmed_count, can: {edit, close, cancel, apply}}`

`applications_count` は却下・キャンセルを含む全件の応募数、`confirmed_count` は確定の数（P2-03 で決定）。`can.edit` は中止以外、`can.apply` は募集中のときだけ（`JobPostingStatus::isEditable()` / `acceptsApplications()` の 1 箇所）。

複製（duplicate）: 勤務日は元の募集と別の日（同じ日は 422）。元の時給が今の最低賃金未満なら 422。

検証: 終了は開始より後。`capacity` は 1〜1000、`hourly_wage` と `transport_allowance` は 100000 以下。`hourly_wage >= config('myteam.min_wage')`。`location_id` は自社の拠点（`Rule::exists()` はグローバルスコープを通らないので `->where('company_id', CurrentCompany::id())` を必ず付ける。`worker_id`（6.5）も同じ。ルートに含まれる親の ID はリクエスト本文から取らずルートモデルバインディングの値を使う）。モデルの `saving` でも、親が自社でなければ例外にする（P2-01 の二重の守り）。

### 6.5 応募・確定（F-09〜F-14）

| メソッド | パス | 入力 | 成功 |
|---|---|---|---|
| GET | `/api/job-postings/{id}/applications` | | 一覧（ページなし） |
| POST | `/api/job-postings/{id}/applications` | `worker_id` | 201 |
| POST | `/api/applications/{id}/confirm` | | 200。定員超過・遷移不可は 422 |
| POST | `/api/applications/{id}/reject` | | 200 |
| POST | `/api/applications/{id}/cancel` | `reason`（`company` / `worker`。必須） | 200 |

`ApplicationResource`: `{id, worker: {id, name}, status: {value, label}, confirmed_at, confirmed_hourly_wage, confirmed_transport_allowance, cancel_reason: {value, label} | null, cancelled_at, attendance: AttendanceResource | null, can: {confirm, reject, cancel, attend}}`

422 の文言（P2-04 で決定。遷移できないときはすべて 422）: 応募は「締め切られた募集には応募できません。」「中止された募集には応募できません。」「無効のワーカーは応募できません。」（`errors.worker_id`）「このワーカーはすでにこの募集に応募しています。」（`errors.worker_id`）。承認は「この応募はすでに確定しています。」「却下された応募は承認できません。」「キャンセルされた応募は承認できません。」「中止された募集の応募は承認できません。」「定員に達しているため承認できません。」。却下は「確定した応募は却下できません。」「この応募はすでに却下されています。」「キャンセルされた応募は却下できません。」。キャンセルは「確定済みの応募だけキャンセルできます。」「この応募はすでにキャンセルされています。」。業務ルール違反は `errors` が空（`worker_id` のものを除く）。入力の検証エラー（`worker_id` / `reason`）は項目のキーに入る。

`can` は「状態として可能か」だけを返します（定員は見ない）。画面はこれでボタンを出し分け、定員エラーは 422 のメッセージで表示します。

### 6.6 勤怠・報酬（F-15、F-16）

| メソッド | パス | 入力 | 成功 |
|---|---|---|---|
| PUT | `/api/applications/{id}/attendance` | `clock_in`（`HH:MM`）`clock_out`（`HH:MM`）`break_minutes` | 200（作成でも更新でも） |

`AttendanceResource`: `{clock_in, clock_out, is_overnight, break_minutes, worked_minutes, wage_pay, transport_allowance, total_pay}`（`clock_in` / `clock_out` は日時）

422 の項目: 形式エラーは `errors.clock_in` / `errors.clock_out` / `errors.break_minutes`（`break_minutes` は必須・0〜1440 の整数）。同時刻は `errors.clock_out`、休憩超過は `errors.break_minutes`、確定済みでない応募は `errors` が空で `message` だけ。`transport_allowance` は保存済みの `total_pay - wage_pay`（保存時に「合計 = 賃金 + 交通費」で決めているので確定時の交通費と同じ値。P2-05 で決定）。

月次集計（6.7）に含めるのは、応募が `confirmed` の勤怠だけ（勤怠の後にキャンセルされた応募の勤怠は行が残るが集計しない。P2-05 のレビューで決定）。

### 6.7 月次集計（F-17、F-18）

| メソッド | パス | 入力 | 成功 |
|---|---|---|---|
| GET | `/api/reports/monthly` | `month`（`YYYY-MM`。必須） | 200 |
| GET | `/api/reports/monthly/csv` | `month` | 200 `text/csv; charset=UTF-8`（同期。F-18）。`Content-Disposition: attachment; filename=monthly-report-{month}.csv` |

```json
{"data": {
  "month": "2026-09",
  "by_worker":   [{"worker_id": 1, "name": "…", "shifts": 1, "worked_minutes": 420, "total_pay": 9600}],
  "by_location": [{"location_id": 1, "name": "…", "shifts": 3, "worked_minutes": 1260, "total_pay": 28800}],
  "total": {"shifts": 3, "worked_minutes": 1260, "total_pay": 28800}
}}
```

### 6.8 ダッシュボード（F-19）

`GET /api/dashboard`

```json
{"data": {
  "upcoming_postings": [{"id": 1, "work_date": "2026-09-20", "start_time": "09:00", "end_time": "17:00",
                         "job_title": "ピッキング", "location_name": "東京倉庫",
                         "capacity": 3, "confirmed_count": 2, "fill_rate": 67}],
  "pending_applications_count": 4,
  "generated_at": "2026-10-01T10:00:00+09:00"
}}
```

`pending_applications_count` は自社の応募中の応募すべて（勤務日・募集の状態を問わない。勤務日が過ぎて応募中のまま残った応募も「未処理」として数える）。`fill_rate` は四捨五入だが、確定数 < 定員なら上限 99（満員でないのに 100% と出さない。P2-06 で決定）。`upcoming_postings` は今日以降の募集中・締切の募集（勤務日の昇順、最大 20 件）。`fill_rate` は整数の％（四捨五入）。P4 でキャッシュします（8 章）。`generated_at` はキャッシュされた時刻なので、画面で「いつの値か」が分かります。

### 6.9 非同期出力（F-23。P4）

| メソッド | パス | 入力 | 成功 |
|---|---|---|---|
| POST | `/api/report-exports` | `month` | **202**（受付だけ） |
| GET | `/api/report-exports` | | 一覧（新しい順、最大 20 件） |
| GET | `/api/report-exports/{id}` | | 200（進捗の確認に使う） |
| GET | `/api/report-exports/{id}/download?expires=…&signature=…` | | 200 `text/csv`。**認証なし・署名で保護**。期限切れ・改ざんは 403 |

`ReportExportResource`: `{id, month, status: {value, label}, progress, row_count, requested_at, completed_at, download_url}`

- `progress` は 0〜100（`job_batches` の進捗）。意味を持つのは queued / processing のときだけ（failed は止まった時点の値のまま。画面は status で判断する）。202 の応答の status は queued とは限らない（4.5）
- `download_url` は `completed` のときだけ入ります。**取得のたびに 30 分有効の署名付きURL を新しく作って返します**
- ダウンロードのルートだけ `auth:sanctum` の外に置きます。URL を持っていること自体が認可です（ブラウザから直接開くため。直接の遷移では Sanctum の Origin / Referer の判定を満たせない）。**メールには署名付き URL を入れず、管理画面の出力一覧へのリンクだけを入れる**（転送されても漏れない。P4-05 で決定）
- 生成中（queued / processing）のまま 30 分（`myteam.report_export_stale_minutes`）を過ぎた出力は、次の依頼のときに failed にして受け付ける（ワーカーが落ちて止まった出力で月が塞がらないように。P4-05 の database-reviewer の指摘）
- 署名が無効・期限切れの 403 は `{"message":"リンクの有効期限が切れたか、正しくありません。","errors":{}}`

### 6.10 通知履歴（F-24。P5・Could）

`GET /api/notification-logs?kind=&status=&page=&per_page=`（per_page は 6.1 の共通どおり最大 100）

`NotificationLogResource`: `{id, kind: {value, label}, recipient: {type: {value, label}, name, email}, subject: {type: {value, label}, id, label}, status: {value, label}, sent_at, failed_at, error, created_at}`（type の value は morph の短い名前、label は「ワーカー」「管理者」「応募」「募集」「集計出力」。null になりうるのは sent_at（送信済みのときだけ入る）と failed_at・error（失敗のときだけ入る）の 3 つ。subject.label の勤務日は表示用の `2026/10/10（土）` の形。P5-01 で決定）

（P5-01 で決定）新しい順（created_at → id の降順）でページング。`kind` / `status` は Enum で検証（不正は 422、空は絞り込まない）。`recipient.email` は記録時の `recipient_email`（実際に送った先）、`recipient.name` と label の名前は今の値（名前を直したら新しい名前で出る）。`subject.label` は応募が「{職種} {勤務日} / {ワーカー名}」、募集が「{職種} {勤務日}」、集計出力が「{月} の月次集計」。宛先・対象の行が消えていたら `name` は記録時のアドレス、`label` は「削除された応募」などを返す。

---

## 7. フロント契約（`web/`）

**Next.js 16 は学習データと違う部分があります。書く前に `web/node_modules/next/dist/docs/` の該当ページを読むこと**（`web/AGENTS.md`）。特に: `middleware.ts` は `proxy.ts` に改名、`cookies()` は `await` が必要、`fetch` は既定でキャッシュされない。

### 7.1 ディレクトリ

```
web/
├── proxy.ts                      セッション Cookie がなければ /login へ（楽観的な検査だけ。本当の判定は API の 401）
├── app/
│   ├── layout.tsx                <html lang="ja">
│   ├── page.tsx                  /dashboard へ redirect
│   ├── (auth)/login/page.tsx     ログイン（Client Component）
│   └── (admin)/
│       ├── layout.tsx            GET /api/user → ヘッダ・ナビ。401 なら /login
│       ├── dashboard/page.tsx
│       ├── locations/            page.tsx / new/page.tsx / [id]/edit/page.tsx
│       ├── workers/              page.tsx / new/page.tsx / [id]/edit/page.tsx
│       ├── job-postings/         page.tsx / new/page.tsx / [id]/page.tsx / [id]/edit/page.tsx
│       ├── reports/monthly/      page.tsx / csv/route.ts（F-18 の中継。Route Handler）
│       ├── reports/exports/      page.tsx（P4）
│       └── notifications/        page.tsx（P5・Could）
├── components/ui/                Table / Field / Button / Alert / Badge / Pagination / LogoutButton（ログアウトだけは Client Component が要るため）
├── components/<機能>/            フォームなど機能ごとの Client Component
└── lib/
    ├── api.ts                    サーバ側の唯一の fetch 入口（server-only）。422 → FormState の変換 toFormState もここ
    ├── browser-api.ts            ブラウザ側（login / logout だけ）
    ├── schemas/                  zod スキーマ（機能ごとに 1 ファイル）
    ├── actions/                  Server Action（機能ごとに 1 ファイル。先頭に "use server"）
    ├── form-state.ts             FormState 型だけ（Client から import できるように）
    ├── status.ts                 状態の value → バッジの色（唯一の対応表）
    └── format.ts                 金額・日付・分 → 表示文字列
```

### 7.2 `lib/api.ts`（直 fetch は禁止。必ずこれを通す）

```ts
import "server-only";

export class ApiError extends Error {
  status: number;                       // 404 / 422 など
  errors: Record<string, string[]>;     // 422 の errors
}

// 成功したら schema.parse した結果を返す。401 は redirect("/login")。それ以外の失敗は ApiError を投げる
export async function apiGet<T>(path: string, schema: z.ZodType<T>): Promise<T>;
export async function apiSend<T>(method: "POST" | "PUT" | "PATCH" | "DELETE", path: string,
                                 body: unknown, schema?: z.ZodType<T>): Promise<T>;
```

- `await cookies()` で受け取った Cookie を `Cookie` ヘッダに転送する
- `Origin` と `Referer` に `WEB_ORIGIN`、`Accept: application/json` を付ける
- 更新系は `XSRF-TOKEN` Cookie を `decodeURIComponent` して `X-XSRF-TOKEN` に付ける
- `cache: "no-store"`（管理画面は常に最新を見る）
- レスポンスは **必ず zod で `parse`** する。型は `z.infer` で導出し、手書きの型定義を置かない

### 7.3 Server Action と `FormState`

```ts
export type FormState = { ok?: boolean; message?: string; errors?: Record<string, string[]>; values?: Record<string, string> };
```

- フォームは `useActionState(action, {})` で組む。422 は `FormState` にして返し、`Field` が `errors[name]` を表示する
- 422 のときは送った値を `values` に入れて返し、入力欄は `defaultValue={state.values?.[name] ?? 元の値}` にする（React 19 の `<form action>` は終了時にフォームをリセットするため。P1-03 のレビューで決定）。`password` と `$ACTION_` で始まるキーは返さない
- 項目に紐づかないエラー（`errors` が空の 422、業務ルール違反）は `Alert`、項目エラーは `Field` に出す。判定は `Alert` の中の 1 箇所
- 検証はサーバ（Laravel）を正とする。**クライアント側に同じ検証を二重に書かない**（HTML の `required` 程度は可）
- 成功したら `revalidatePath()` して `redirect()`。状態ボタン（承認・却下など）は `redirect` せず同じページを再検証する
- Server Action は公開エンドポイントと同じ扱い。認可は Laravel の 401 / 404 / 422 に任せ、Action 側で勝手に判定しない

### 7.4 画面と使う API

| 画面 | パス | 使う API | 要件 |
|---|---|---|---|
| ログイン | `/login` | csrf-cookie、login | 動線 1 |
| ダッシュボード | `/dashboard` | dashboard | F-19 |
| 拠点一覧・登録・編集 | `/locations` … | locations | F-01 |
| ワーカー一覧・登録・編集 | `/workers` … | workers | F-02〜F-04 |
| 募集一覧・登録・編集 | `/job-postings` … | job-postings、locations | F-05、F-06 |
| 募集詳細 | `/job-postings/[id]` | job-postings、applications、applicable-workers、attendance | F-07〜F-16 |
| 月次集計 | `/reports/monthly` | reports/monthly、report-exports（POST。非同期出力の依頼） | F-17、F-18、F-23 |
| 非同期出力 | `/reports/exports` | report-exports | F-23 |
| 通知履歴 | `/notifications` | notification-logs | F-24 |

募集詳細が中心の画面です。応募の追加、承認・却下・キャンセル、勤怠の入力、報酬の表示をすべてここで行います。

### 7.5 表示の規約

- UI の文言は要件 2 章の用語（企業 / 管理者 / 拠点 / ワーカー / 募集 / 応募 / 確定 / 却下 / キャンセル / 勤怠 / 報酬）
- 金額は `9,600円`、労働時間は `7時間00分`、日付は `2026/09/20（日）`
- 表のセル・バッジ・ボタンは `whitespace-nowrap`（第2弾の教訓: 折り返しで崩れた）
- 状態の色は `lib/status.ts` の 1 箇所。ラベルは API の `label` をそのまま出す
- 例外（P3-02 で決定）: 一覧の「状態で絞り込む」選択肢だけは、API に Enum の一覧を返す口がないので、ラベルを `lib/status.ts` の隣（絞り込み部品）に置く。値は docs/01 4.1 と同じ。表のバッジは API の label を出す
- 例外（P3-02 で決定）: 入力欄のない項目の 422（例: 複製フォームの `errors.hourly_wage`）は、そのフォームの下にまとめて出す（Alert は項目エラーがあると出さないため）
- 拠点の選択肢は `per_page=100` の 1 ページ分（101 件以上は想定しない）
- 例外（P5-01 で決定）: 通知履歴の「種別」「状態」の絞り込みの選択肢（NotificationKind / NotificationStatus）のラベルも、同じ理由で web に置く。表の表示は API の label を出す
- 例外（P3-03 で決定）: キャンセル理由の選択肢（company 企業都合 / worker ワーカー都合）のラベルも、状態の絞り込みと同じ理由で web に置く。表示は API の label を出す
- 表の中の操作（承認・却下・キャンセル・勤怠・締切・中止）の 404 は not-found 画面にせず、行の Alert に API の message を出す

---

## 8. 非同期の契約（P4。詳細は [`03_非同期処理の設計.md`](03_非同期処理の設計.md)）

| 要件 | きっかけ | 積むもの | キュー |
|---|---|---|---|
| F-20 確定通知 | `ApplicationConfirmed`（コミット後） | `SendApplicationConfirmedMail(applicationId)` | `mails` |
| F-21 中止通知 | `JobPostingCancelled`（コミット後） | 確定済みだった応募ごとに `SendPostingCancelledMail(applicationId)` | `mails` |
| F-22 前日リマインド | スケジューラ → `app:send-reminders` | 翌日勤務の確定応募ごとに `SendShiftReminderMail(applicationId, workDate)`（ID と日付の文字列だけ。モデルは渡さない。どの日の勤務を知らせるジョブかを決めるため。P4-04 で決定） | `mails` |
| F-23 CSV 出力 | `POST /api/report-exports` | `Bus::batch([BuildLocationCsvChunk × 拠点数])` → `then`: `FinalizeReportExport` | `default` |
| F-19 キャッシュ | `Application` / `JobPosting` / `Location`（拠点名を表示するため。P4-07 で追加）の保存・削除（コミット後） | （ジョブなし）`DashboardCacheObserver` → `DashboardService::forget($model->company_id)` | |

規約:

- ジョブには **ID を渡す**。モデルを渡さない（実行時点の最新状態を読むため）
- ジョブの中に業務ルールを書かない。サービスを呼ぶだけ
- メールを送るジョブは必ず `NotificationLogService::claim()` → 送信 → `markSent()` の順（冪等性）
- イベントは `ShouldDispatchAfterCommit`。ロールバックされたら通知は出ない
- キャッシュのキーには企業ID を含める（`dashboard:{company_id}`）。破棄は `DashboardService::forget()` の 1 箇所で、呼ぶのは Observer（`ShouldHandleEventsAfterCommit`）だけ
- 同じキーのジョブを同時に走らせない（`WithoutOverlapping`）。リマインドのジョブは `ShouldBeUnique`

---

## 9. テスト契約

`web/` のテストは書きません（決定9）。画面は README の手動チェックリストで確認します。

### 9.1 必須のテストファイル（Stop フックが存在を検査する）

| ファイル | 守るもの | 検査が始まるフェーズ |
|---|---|---|
| `api/tests/Feature/ApplicationTest.php` | 定員超過の拒否（要件 7 章） | 3 |
| `api/tests/Unit/PayrollCalculatorTest.php` | 報酬計算（要件 7 章） | 3 |
| `api/tests/Unit/ApplicationStatusTest.php` | 状態遷移の拒否（要件 7 章） | 3 |
| `api/tests/Feature/AcceptanceScenarioTest.php` | 動線 10 ステップを API 経由で 1 本 | 3 |
| `api/tests/Feature/NotificationIdempotencyTest.php` | 同じ通知を二重に送らない | 5 |
| `api/tests/Feature/FailedJobTest.php` | 失敗が記録され再実行できる | 5 |

### 9.2 `tests/TestCase.php`

- `RefreshDatabase` を適用
- `$this->company` / `$this->admin` を `setUp` で作り、`CurrentCompany::set($this->company->id)` する
- `actingAsAdmin(): static` — `$this->actingAs($this->admin)`（`auth:sanctum` は web ガードのログインを受け付ける）
- HTTP は `getJson` / `postJson` / `putJson` / `patchJson` / `deleteJson` を使う
- `phpunit.xml` に `APP_FAKER_LOCALE=en_US`、`TELESCOPE_ENABLED=false`

### 9.3 書き方

- テスト名は仕様が読める日本語（`test_定員に達していると承認できない`）
- 1 つの受け入れ条件 = 1 テスト。異常系を必ず含める
- 他社データの分離は機能ごとに 1 本（404 になること）
- 非同期は `Queue::fake()` / `Bus::fake()` / `Mail::fake()` で「積まれる・積まれない」を、ジョブ単体は `handle()` を直接実行して結果を検証する

---

## 10. 担当と所有ファイル

並列に作業するのは P2（api）と P3（web）だけです。基盤は直列で作り、確定したらロックします。

### 10.1 P1・P2 基盤（直列。`implementer-api` 1 体）

`api/database/migrations/*`、`api/app/Models/*`、`api/app/Enums/*`、`api/app/Support/*`、`api/app/Exceptions/*`、`api/app/Http/Middleware/*`、`api/app/Providers/*`、`api/bootstrap/app.php`、`api/config/*`、`api/routes/api.php`（**全ルートを先に登録**し、未実装のコントローラは 501 を返す）、`api/database/factories/*`、`api/database/seeders/*`、`api/tests/TestCase.php`、`api/lang/*`

基盤が `database-reviewer` のレビューを通ったら、人が `.claude/.migrations-locked` を置きます。以後、既存のマイグレーションは編集できません（新しいファイルを足すのは可）。

### 10.2 P2 の並列担当（api）

| 担当 | 範囲 | 所有ファイル |
|---|---|---|
| A マスタ | F-01〜F-04 | `LocationController` `WorkerController` `LocationService`、対応する `Requests` `Resources`、`tests/Feature/{Location,Worker}Test.php` |
| B 募集・応募 | F-05〜F-14 | `JobPostingController` `ApplicationController` `JobPostingService` `ApplicationService` `Events/*`、対応する `Requests` `Resources`、`tests/Feature/{JobPosting,Application}Test.php` `tests/Unit/ApplicationStatusTest.php` |
| C 勤怠・集計 | F-15〜F-19 | `AttendanceController` `MonthlyReportController` `DashboardController` `AttendanceService` `PayrollCalculator` `PayrollResult` `MonthlyReportService` `MonthlyReport` `DashboardService`、対応する `Requests` `Resources`、`tests/Feature/{Attendance,MonthlyReport,Dashboard}Test.php` `tests/Unit/PayrollCalculatorTest.php` |

B と C の境界: C の `AttendanceService::save()` は B の `Application`（確定済みで `confirmed_hourly_wage` が入っている）を読むだけです。C は応募の状態を変えません。

`AcceptanceScenarioTest.php` は A・B・C が終わった後にリードが `tester` に書かせます。

### 10.3 P1・P3 の web

| 担当 | 範囲 | 所有ファイル |
|---|---|---|
| 基盤（直列。P1） | 共通部品・ログイン・拠点 | `proxy.ts` `app/layout.tsx` `app/page.tsx` `app/(auth)/*` `app/(admin)/layout.tsx` `app/(admin)/locations/*` `components/ui/*` `lib/api.ts` `lib/browser-api.ts` `lib/form-state.ts` `lib/status.ts` `lib/format.ts` `lib/schemas/{common,user,location}.ts` `lib/actions/location.ts` |
| WA | ワーカー | `app/(admin)/workers/*` `components/workers/*` `lib/schemas/worker.ts` `lib/actions/worker.ts` |
| WB | 募集・応募・勤怠 | `app/(admin)/job-postings/*` `components/job-postings/*` `lib/schemas/{job-posting,application,attendance}.ts` `lib/actions/{job-posting,application,attendance}.ts` `lib/actions/helpers.ts`（P3-03 で追加。Action の共通の小関数。"use server" ではない） |
| WC | 集計・ダッシュボード | `app/(admin)/dashboard/*` `app/(admin)/reports/*` `components/reports/*` `lib/schemas/{report,dashboard}.ts` |

`components/ui/*` と `lib/api.ts` は基盤の所有です。WA〜WC が変えたくなったらリードに報告して止まります。

### 10.4 P4・P5（直列）

非同期は学習の主題なので並列にしません。F-20 → F-21 → F-22 → F-23 → F-19 キャッシュ → 通知履歴の順に、1 つずつ `/issue-flow` を回します。

### 10.5 同時に動かす数の上限

実装は最大 3 体、レビューは最大 4 体まで（第2弾で 12 体を同時に動かしてセッション上限に達した）。同じファイルを触る担当は同時に 1 体だけです。

---

## 11. フェーズ

`.claude/.phase` に数字を書きます。**書き換えるのは人だけ**です。

| フェーズ | 内容 | 終わりの条件 |
|---|---|---|
| 0 | ハーネス構築・ゲート確認 | `docs/04` 7 章のゲート 5 項目が通る |
| 1 | API 骨格。認証と拠点を API → 画面まで縦に 1 本 | ログインして拠点を登録・一覧できる。未ログインで API が 401 |
| 2 | ドメイン（api）。基盤 → A / B / C | F-01〜F-19 の API が通り、7 章の 3 種と動線テストが緑 |
| 3 | フロント（web）。WA / WB / WC | 動線 10 ステップが画面で通る（**前半完了**） |
| 4 | 非同期 | F-20〜F-23 と F-19 のキャッシュが動く |
| 5 | 仕上げ | 通知履歴、README、docs の「想定と実際」 |
| 6 | Could | F-25 Broadcasting |

---

## 12. 設計判断と理由

| 論点 | 判断 | 理由 |
|---|---|---|
| 定員チェックの一貫性 | SQLite の `transaction_mode=IMMEDIATE` で、承認のトランザクションを開始時点で直列化する | SQLite は `lockForUpdate()` を無視する。`IMMEDIATE` なら 2 つの承認が同時に来ても、後の方は先の方のコミットを待ってから確定数を数える。`lockForUpdate()` も意図を示すために書く（MySQL に移せばそのまま効く） |
| テナント分離の持ち方 | `CurrentCompany`（Context）＋グローバルスコープ。未設定なら例外 | `auth()->user()` に頼ると、ログインユーザーのいないキューのジョブで条件が外れて全社のデータが見えてしまう。Context はジョブに引き継がれる |
| Policy を作らない | 他社は 404、状態による可否は 422 | 第2弾で学習済み。今回は API・Next・非同期に時間を使う |
| Enum のラベルを API が返す | `{value, label}` | 日本語の文言を 2 箇所に書かない。web は色だけ持つ |
| ログイン以外はブラウザから API を叩かない | Next サーバが中継 | Cookie と CSRF を扱う場所を `lib/api.ts` の 1 箇所にできる。CORS で通すオリジンも 1 つで済む |
| 署名付きURL を認証の外に置く | 署名＋30 分の期限で保護 | ブラウザの直接遷移では Sanctum の stateful 判定（Origin / Referer）を満たせない。URL は取得のたびに作り直し、メールには入れない（P4-05） |
| 同じ月の出力の同時依頼 | トランザクションの先頭で企業の行を `lockForUpdate()` してから生成中を確認 | SQLite では IMMEDIATE で直列化される。MySQL に移してもそのまま効く（部分インデックスの UNIQUE は MySQL にないので使わない。P4-05） |
| 同期 CSV（F-18）は Route Handler で中継 | `app/(admin)/reports/monthly/csv/route.ts` | 上と同じ理由。こちらは署名を使わず、Next サーバが Cookie を転送して取ってくる。**同じ「ファイルを渡す」を 2 通りで実装して比べる** |
| キャッシュの破棄を Observer に | `Application` / `JobPosting` / `Location` の Observer がコミット後に破棄 | サービスの各メソッドに書くと経路を足したとき漏れる。担当 B が担当 C のキャッシュを知らなくて済む。Observer は担当 C の所有（`app/Observers/`）で、P4 に足す |
| 通知の記録を自前のテーブルに | `notification_logs`（ポリモーフィック）＋ `dedupe_key` の UNIQUE | 冪等性を DB 制約で保証できる。Laravel の `notifications` テーブルは「管理者のアプリ内通知」にだけ使う |
| ジョブに ID を渡す | `SerializesModels` に頼らない | 実行時点の最新状態を読む。確定後すぐキャンセルされた応募に確定通知を送らない |

---

## 13. 実装時に確かめること（未確認の前提）

書いた時点で実物を確かめていない前提です。着手したら最初に確かめ、違っていたらこの文書を直します。

| # | 前提 | 確かめ方 |
|---|---|---|
| 1 | Fortify の `/login` は `Accept: application/json` なら 200 の JSON、`/logout` は 204 を返す | P1 で `LoginTest` を書いて確認 |
| 2 | `actingAs($user)` で `auth:sanctum` を通る | 同上 |
| 3 | `transaction_mode=IMMEDIATE` が `DB::transaction()` に効く | `php artisan tinker` で `DB::enableQueryLog()` か、2 つのターミナルで待たされるのを見る |
| 4 | `Context` に入れた値がキューのジョブに引き継がれる | P4 の最初のジョブのテストで確認 |
| 5 | **確認済み（P1-03）: 届く。** `Cookie` だけだと 401、`Origin` か `Referer` のどちらかがあれば 200。更新系は `X-XSRF-TOKEN` がないと 419 | Next 16 のサーバ側 fetch に付けた `Origin` ヘッダがそのまま Laravel に届く | P1 で Telescope なしなら `Log::info(request()->headers->all())` を一時的に入れて確認（終わったら消す） |
| 6 | 同じブラウザで第2弾（`localhost:8001`）を開いていると `XSRF-TOKEN` Cookie が上書きし合う | 手動確認のときは第2弾のタブを閉じるか、別のブラウザプロファイルを使う |
