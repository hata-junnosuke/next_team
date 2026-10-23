# myteam 企業側管理画面（next_team）

企業が自社の人材プール（OB・OG／スポットワーカー）を持ち、単発の募集を出して働いてもらうまでを管理する「企業側管理画面」です。`api/`（Laravel の JSON API）と `web/`（Next.js）の 2 つで動き、通知メールや CSV の出力は **Job / Queue で非同期に** 処理します。

| 文書 | 内容 |
|---|---|
| [`../00_要件定義.md`](../00_要件定義.md) | 要件 |
| [`docs/01_技術仕様_next.md`](docs/01_技術仕様_next.md) | 契約（テーブル・サービス・API・画面・担当と所有ファイル）。開発の正 |
| [`CLAUDE.md`](CLAUDE.md) | AI エージェントで開発するときの絶対ルール |
| `docs/` のその他 | 計画・学習のポイント・非同期の設計・ループの仕組み（学習用の資料） |

---

## 現状

- 機能: F-01〜F-24 を実装済み。F-25（Broadcasting、Could）は未着手
- タスク: GitHub Issues で管理します（本文のチェックリスト、`requires-human` ラベル）。ブランチは `issue/<番号>`
- 合否: PR ごとに CI（`.github/workflows/ci.yml`）が `make verify` を回します

### 人しかやらないこと

マイグレーションのロック（`.claude/.migrations-locked`）、`.env` の変更、フックと `settings.json` の変更の承認、計画の承認、`main` へのマージ、画面の手動確認。

### 画面の目視確認（issue #3 デザインの見直し）

web は自動テストを書かないので、人が実ブラウザで確かめて確認日を入れます。

| # | 画面 | 見る点 | 確認日 |
|---|---|---|---|
| V-1 | 全画面 | computed `font-family` が Noto Sans JP。Arial に戻る画面がない。数字が等幅 |  |
| V-2 | 全画面 | OS をダークモードにしても暗転・混在しない（ライト固定） |  |
| V-3 | 任意のフォーム | `Button` の primary / secondary / danger / disabled / pending が同じ高さ。Tab でフォーカスの印が出る |  |
| V-4 | 拠点の登録 | `Field` の通常・フォーカス・エラーの 3 状態が区別できる。エラー文が入力欄の直下に赤で出る |  |
| V-5 | 任意の一覧 | 表の見出し行が濃紺（sidebar 色）の地、行ホバーで色が変わる、0 件の文言が中央。セルが折り返さない |  |
| V-6 | 募集一覧・詳細・通知履歴・非同期出力 | バッジの 6 トーンが判別できる。ラベルは API の文言のまま |  |
| V-7 | 管理画面 | サイドバーで現在の画面が強調される（`aria-current="page"`）。企業名・管理者名・ログアウトが見える。幅 1024px 未満で上部に畳まれ、横スクロールしない |  |
| V-8 | `/foo`、他社 id の編集 URL、API 停止時 | 3 種のエラー画面が崩れず、戻り先のリンク（または再試行）が押せる |  |
| V-9 | ログイン | 左ブランド面 + 右フォーム。誤ったパスワードのエラーが目立つ。幅 375px で縦積み |  |
| V-10 | 拠点・ワーカー一覧 | 見出し → 登録ボタン → 絞り込みカード → 表 → ページ送りが同じ余白で並ぶ |  |
| V-11 | 募集詳細 | 募集情報が label-over-value のカード。締切・中止が見出しの右で、422 の理由を出しても見出しが動かない。応募表の操作が小サイズ |  |
| V-12 | 募集詳細の勤怠入力 | 勤怠の入力行が区別できる。休憩超過のエラーが欄の下に出る。「深夜跨ぎ」「集計対象外」の印が読める |  |
| V-13 | ダッシュボード | 指標カードの大きな数字、充足率の進捗バー（値は API のまま）、集計時刻 |  |
| V-14 | 月次集計・非同期出力・通知履歴 | 合計行が強調される。状態バッジ・進捗・ダウンロードが読める。通知履歴のエラー列だけ折り返す |  |

---

## 構成

| 項目 | 内容 |
|---|---|
| `api/` | Laravel 13.34 / PHP 8.4（8.4 未満では SQLite の `IMMEDIATE` が効かないので必須）。JSON API 専用 |
| `web/` | Next.js 16.3.8 / React 19.2 / TypeScript / Tailwind CSS 4（App Router） |
| DB | SQLite（`api/database/database.sqlite`。テストは in-memory） |
| 認証 | Sanctum SPA 認証（Cookie + CSRF）＋ Fortify ヘッドレス |
| キュー・キャッシュ | Redis（Docker）。ワーカーは Horizon（`laravel/horizon` v5.50.0。`mails` と `default` を別のスーパーバイザで各 2 プロセス）。監視は Telescope（`laravel/telescope` v5.25.0、dev。ローカルだけ登録） |
| メール | Mailpit（Docker） |
| テスト | api は PHPUnit。web は型チェックと ESLint。画面は issue に書いた確認項目を人が確かめる |
| 整形 | api は Pint、web は Prettier（編集のたびにフックが自動整形） |
| AI ハーネス | `.claude/`（agents 9 / skills 13 / rules 8 / hooks 8） |
| CI | GitHub Actions（`.github/workflows/ci.yml`）。main 向けの PR と main への push で `make verify` |

```
next_team/
├── CLAUDE.md          絶対ルール
├── Makefile           コマンドの入口（make help）
├── compose.yaml       redis / mailpit
├── docs/              01 契約（開発の正）と、学習用の資料
├── .claude/           hooks（強制）/ agents / skills（手順・規約）/ rules
├── .github/           CI / issue と PR のテンプレート
├── api/               Laravel（JSON only）
└── web/               Next.js
```

### ポート

| 用途 | URL |
|---|---|
| 画面 | http://localhost:3002 |
| API | http://localhost:8002 |
| Mailpit | http://localhost:8025 |
| Horizon（ローカルのログイン済み管理者だけ） | http://localhost:8002/horizon |
| Telescope（ローカルのログイン済み管理者だけ） | http://localhost:8002/telescope |

8000・8001・3000 は他のプロジェクトが使うので避けています。`make ports` で、8002 と 3002 をどのフォルダのプロセスが使っているかを確かめられます。

---

## 起動手順

前提: PHP 8.4、Composer、Node.js 20 以上、Docker。

### 初回だけ

```bash
cd next_team
(cd api && composer install && cp -n .env.example .env && php artisan key:generate && touch database/database.sqlite && php artisan migrate)
(cd web && npm install && cp -n .env.example .env.local)
make up        # redis / mailpit
make verify    # api: Pint + テスト / web: 型 + ESLint が緑になること
```

issue ごとの worktree は `/issue-start <番号>` が作ります（依存・`.env` のコピー・DB まで）。Orca を使用していれば、その worktree の画面（API `8100 + 番号` / web `3100 + 番号`）と Claude も開き、Claude には `/epic-flow` か `/issue-flow` を最初の指示として渡します。PR がマージされたら main で `/dev-refresh` が片付け、残っている worktree の遅れと衝突の見込みを出します（main で起動すると片付け候補が出ます）。main の取り込みは各 worktree の Claude が、タスクの始めと PR を作る前に行います。

### 毎回

```bash
make up            # redis / mailpit（Docker。起動済みなら不要。make ps で確認）
make fresh         # 初回とデータを作り直したいときだけ。DB を作り直してシード
make api-serve     # ターミナル 1: http://localhost:8002
make web-dev       # ターミナル 2: http://localhost:3002
make horizon       # ターミナル 3: キューワーカー（Horizon）
make schedule      # ターミナル 4: スケジューラ（F-22 の前日リマインド 毎日 18:00 と、Horizon の Metrics 用 horizon:snapshot 5 分ごと）
```

- ログインは http://localhost:3002/login で **`admin@example.com` / `password`**（`make fresh` のシード。企業「株式会社サンプル」の管理者）
- キュー・キャッシュ・メールの設定（`QUEUE_CONNECTION=redis`・`CACHE_STORE=redis`・`MAIL_MAILER=smtp` など）は `api/.env` に書きます。`api/.env.example` の値が基準です
- **`make api-serve`（`php artisan serve`）を起動するときに、環境変数で `QUEUE_CONNECTION=redis` などを渡しても効きません。** `php artisan serve` は、実際にリクエストを処理する子プロセスに、`ServeCommand::$passthroughVariables` にある変数（`APP_ENV`・`PATH` など）しか渡さないためです。`.env` を変えずに環境変数で試したいときは、`cd api/public && QUEUE_CONNECTION=redis ... php -S 127.0.0.1:8002 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php` のように組み込みサーバーを直接起動します（`make horizon` など artisan の他のコマンドは、環境変数がそのまま効きます）
- Horizon は Redis のキューしか処理しません。`api/.env` が `QUEUE_CONNECTION=database` のときは、`make horizon` の代わりに `cd api && php artisan queue:work --queue=mails,default` を動かします。どちらのワーカーも動かさないと、確定通知・中止通知・リマインド・CSV の非同期出力はキューに積まれたまま実行されません
- Horizon と Telescope の画面は、管理画面にログインした同じブラウザで開きます（ローカル環境かつログイン済み管理者だけ。未ログインは 403）。Telescope の記録テーブル `telescope_entries` は `make fresh` で作られます
- メールは Mailpit（http://localhost:8025）で見ます
- `make schedule` の代わりに、リマインドは `cd api && php artisan app:send-reminders --date=2026-10-03` で手動実行できます（`php artisan schedule:list` に `0 18 * * *` で出ます）
- Horizon の Metrics（Jobs / Queues のグラフ）は `make schedule` が 5 分ごとに回す `horizon:snapshot` で溜まります（`schedule:list` に `*/5 * * * *` で出ます）。急ぐときは `cd api && php artisan horizon:snapshot` を数分おきに手で叩きます（docs/03 8 章）
- 同じブラウザで、別のプロジェクトの Laravel（`localhost:8001` など）を開いていると `XSRF-TOKEN` の Cookie が上書きし合います。閉じるか、別のプロファイルで確かめます

### よく使うコマンド

```bash
make help                       # 一覧
make verify                     # Stop フック・CI と同じ検証
make api-test FILTER=Foo        # テストを絞って実行
make fresh                      # DB を作り直してシード
make verify-full                # verify + Prettier の整形チェック + next build
make ports                      # 8002 / 3002 をどのフォルダのプロセスが使っているか
make down                       # redis / mailpit を止める（データは残る）
```

---

## AI エージェント組織（`.claude/`）の使い方

| やりたいこと | コマンド |
|---|---|
| issue を作る | `/issue-create task <概要>` / `/issue-create bug <概要>`（`.github/ISSUE_TEMPLATE/` どおり） |
| 実装の前に計画だけ立てる | `/plan <issue 番号>` |
| issue の作業場所（worktree）を作る | `/issue-start <issue 番号>`（main で。Orca なら画面と Claude も開く） |
| タスクが複数ある issue をまとめて進める | `/epic-flow <issue 番号>`（Orca では `/issue-start` が worktree の Claude に渡す。issue の worktree の中なら人が打ってもよい。main の作業ツリーではフックが止める） |
| タスク 1 件を実装 → テスト → レビューまで回す | `/issue-flow <issue 番号> [タスクID]`（issue の worktree で。main の作業ツリーではフックが止める） |
| 検証する | `/verify`（quick）/ `/verify pre-commit` / `/verify full` |
| レビューだけする | `/code-review` |
| PR を作って CI を確かめる | `/pr-create <issue 番号>`（issue の worktree で。`.github/pull_request_template.md` どおり） |
| 開発環境を最新化する（マージ済みの worktree を片付ける） | `/dev-refresh [issue 番号]`（main で。未コミット・未マージは残し、遅れと衝突の見込みを出す） |

応答を終えようとするたびに Stop フックが検証を回し、赤なら差し戻します（5 回で打ち切り。前回の緑から変更がなければ省略）。PR では CI が同じ `make verify` を回します（手元の速い合否が Stop フック、チームの関門が CI）。api のタスクの完了条件はテスト名、web のタスクは「型と ESLint が緑 ＋ issue に書いた画面の確認項目」です。差し戻しなどから分かった決まりは、`CLAUDE.md`・`.claude/rules/`・スキルに PR で直接書きます。

---

## 設計で迷った点と判断

契約としての決定は [`docs/01`](docs/01_技術仕様_next.md) の「設計判断と理由」にあります。ここには **実装してみて迷い、決め直したもの** を並べます。

### 1. 定員チェックは SQLite の `IMMEDIATE` で直列化する

- どこ: `api/config/database.php`（`transaction_mode => 'IMMEDIATE'`、`journal_mode => 'wal'`、`busy_timeout => 5000`）、`api/app/Services/ApplicationService.php`
- 迷った点: SQLite は `lockForUpdate()`（Rails の `lock`。`SELECT ... FOR UPDATE`）を無視します。2 つの承認が同時に来ると、両方が「確定数 2 / 定員 3」を読んで両方確定し、定員を超えます
- 判断: トランザクションの開始時に書き込みロックを取る `BEGIN IMMEDIATE` にしました。後から来た承認は、先の承認のコミットを待ってから確定数を数えます（2 プロセスを並べ、後の方が約 2 秒待つことを確かめました）。`lockForUpdate()` も意図を示すために書き、ロックの順は「募集 → 応募 → 確定数」にそろえています（MySQL に移したときにデッドロックしにくい順）
- 落とし穴: `transaction_mode` は PHP 8.4 以上でしか `BEGIN IMMEDIATE` にならず、8.3 では黙って普通の `BEGIN`（DEFERRED）になります。`api/composer.json` を `^8.4` にしたのはこのためです
- 参考: https://readouble.com/laravel/13.x/ja/database.html

### 2. メールのジョブは `$tries = 0` + `$maxExceptions = 3` + `retryUntil()`

- どこ: `api/app/Jobs/SendApplicationConfirmedMail.php`、`SendPostingCancelledMail.php`、`SendShiftReminderMail.php`
- 迷った点: 最初は `$tries = 3`（Rails の `retry_on attempts: 3`）でした。ところが流量制限のジョブミドルウェア `RateLimited` でキューへ戻された回も「1 回の試行」に数えられます。流量の上限に 3 回当たっただけで、メールを 1 度も送ろうとしないまま `failed()` に入り、`notification_logs` に「届いていない」記録が残りませんでした（tester が再現）
- 判断: 試行回数では打ち切らず（`$tries = 0` は無制限）、「送信の例外が 3 回」（`$maxExceptions`）か「積んでから 60 分」（`retryUntil()`、`MYTEAM_MAIL_RETRY_UNTIL_MINUTES`）で打ち切ります。期限切れで `failed()` に入ったときは対象を読み直して失敗の記録を作ります（`NotificationLogService::recordFailure()`）
- 参考: https://readouble.com/laravel/13.x/ja/queues.html （「最大試行回数／タイムアウト値」「レート制限」）

### 3. `dedupe_key` は全社で一意のまま。代わりに `claim()` で企業を確かめる

- どこ: `api/database/migrations/2026_10_03_100000_create_notification_logs_table.php`、`api/app/Services/NotificationLogService.php`
- 迷った点: `notification_logs` の宛先と対象はポリモーフィック（Rails の `belongs_to :recipient, polymorphic: true`）なので、他のテーブルのような「保存時に親が自社か」の検査が効きません。security-reviewer の重大指摘で、他社の応募を対象に `claim()` されると、他社の宛先（個人情報）が自社の履歴に入り、さらに全社で一意の `dedupe_key` を先取りして他社の通知を止められることが分かりました
- 判断: UNIQUE を「企業ごと」（`company_id, dedupe_key`）に変える案もありましたが、キーには応募 ID や出力 ID が入っていて元々全社で重なりません。問題は UNIQUE の範囲ではなく「他社のものを対象にできること」なので、`claim()` の入口で、宛先と対象が未保存・`company_id` なし・他社のときは `LogicException` にしました。`error` 列には例外の生のメッセージ（SQL のバインド値や SMTP のユーザー名を含みうる）を残さず固定の文言にしています

### 4. 同時に 2 本走った通知ジョブは「少なくとも 1 回」（at-least-once）で受け入れる

- どこ: 各メールのジョブの `middleware()`（`WithoutOverlapping(...)->dontRelease()`）
- 迷った点: `dedupe_key` の UNIQUE が防ぐのは「送信済みの後の 2 通目」だけです。2 本のジョブが同時に同じ送信待ちの行を受け取ると、両方が送ります（database-reviewer の指摘）。DB で厳密に止めるには `claimed_at` 列を足して「条件付き UPDATE の影響行数が 1 のときだけ送る」形にします
- 判断: 新しい列は足さず、ジョブミドルウェア `WithoutOverlapping`（キャッシュのロック）で抑え、保証の範囲を at-least-once にしました。厳密にしたくなったら、新しいマイグレーションで `claimed_at` を足せます。2 本目は `dontRelease()` で捨てます。後回しにすると、その回も試行に数えられ、送信済みなのに「失敗」が残るためです

### 5. 止まった「生成中」の出力は、30 分を過ぎたら次の依頼で failed にする

- どこ: `api/app/Services/ReportExportService.php`、`api/app/Jobs/FinalizeReportExport.php`、`MYTEAM_REPORT_EXPORT_STALE_MINUTES`
- 迷った点: 同じ月の出力が生成中なら 422「同じ月の出力が生成中です。」にしています。ところがワーカーが落ちると、ロックの期限（`expireAfter`）がキューの `retry_after` より長い場合、再実行がロックに当たって黙って捨てられ、どの `failed()` も呼ばれません。出力が processing のまま残り、その月は永遠に 422 になります（database-reviewer の重大指摘）
- 判断: 期限を「ジョブの `$timeout`（50 秒）< `expireAfter`（60 秒）< キューの `retry_after`（90 秒）」の順にそろえ、さらに逃げ道として、30 分を過ぎた生成中は次の依頼のときに failed にしてバッチをキャンセルします。同時の依頼は企業の行を `lockForUpdate()` してから生成中を確かめます
- 補足: processing への切り替えは `Bus::batch()->before()` で行います。sync キュー（テスト）では `dispatch()` の中で `then` まで走り、しかもバッチ全体が 1 つのトランザクションに入るため、`catch` で入れた failed が巻き戻ることが分かったからです
- 参考: https://readouble.com/laravel/13.x/ja/queues.html （「ジョブバッチ」「重複ジョブの防止」）

### 6. ダッシュボードのキャッシュは「5 分」と「翌日 0:00」の早い方で切れる

- どこ: `api/app/Services/DashboardService.php`（`Cache::remember`。Rails の `Rails.cache.fetch`）、`api/app/Observers/DashboardCacheObserver.php`
- 迷った点: ダッシュボードは「今日以降の募集」を出すので、0:00 をまたぐと昨日の募集が残ります。キーに日付を入れる案は、`forget()` がどの日のキーを消すかを計算する必要があり、0:00 をまたいだ破棄で前日のキーが残るので採りませんでした
- 判断: 有効期限を「`MYTEAM_DASHBOARD_CACHE_SECONDS`（既定 300 秒）後」と「翌日 0:00」の早い方にしました。破棄は Observer がコミット後（`ShouldHandleEventsAfterCommit`。Rails の `after_commit`）に行い、対象は `Application`・`JobPosting` に加えて `Location`（拠点名を一覧に出しているので。tester が古い拠点名が残るのを再現）です。キーは `dashboard:{company_id}` で、消すのは変更されたモデルの企業のキャッシュです
- 参考: https://readouble.com/laravel/13.x/ja/cache.html 、https://readouble.com/laravel/13.x/ja/eloquent.html （「オブザーバ」）

### 7. 署名付き URL はメールに入れない

- どこ: `api/app/Http/Resources/ReportExportResource.php`（`URL::temporarySignedRoute()`）、`api/routes/api.php` の `report-exports.download`（`signed` ミドルウェア）、`api/app/Notifications/ReportExportReady.php`
- 迷った点: 署名付き URL（Rails の `signed_id` + `expires_in` に近い）は「URL を持っていること自体が認可」です。完了通知のメールに入れると、30 分で切れるリンクがメールに残り続け、転送されれば他人もダウンロードできます
- 判断: URL は `GET /api/report-exports/{id}` を取得するたびに 30 分の期限で作り直し、`completed` のときだけ返します。メールは「完了した」ことだけを知らせ、ダウンロードは管理画面（`/reports/exports`）から行います。同じ理由で、ワーカー宛てのメールからは管理画面へのリンクを外し、`x-mail::message`（ヘッダーに `APP_URL` へのリンクが入る）ではなく `x-mail::layout` を直接使っています
- 参考: https://readouble.com/laravel/13.x/ja/urls.html （「署名付きURL」）

### 8. 出力一覧は自動で読み直さない

- どこ: `web/app/(admin)/reports/exports/page.tsx`、`web/components/reports/ExportTable.tsx`
- 迷った点: 生成中の出力があれば、画面が数秒ごとに自動で読み直す仕組みを入れていました
- 判断: 「状態が変わったら画面が自動で切り替わる」は F-25（Broadcasting、Could）の範囲なので外し、「最新の状態に更新」リンクにしました。Server Component なので、開き直せば最新の状態を取り直します。生成中・失敗の判定は `web/lib/status.ts` の `isInProgressExportStatus()` / `isFailedExportStatus()` の 2 つだけにしています（業務ルールを web に書かない）

### 9. Markdown メールの差し込みは無害化を足す

- どこ: `api/app/Mail/Concerns/FormatsMarkdownText.php`、`api/resources/views/mail/*.blade.php`
- 迷った点: Markdown メールの `{{ }}` は HTML しか無害化しません。ワーカーの氏名に `[x](https://evil)` を入れるとリンクになります
- 判断: `Markdown::withSecuredEncoding()` を使い、それでも残る表の区切り `|` と改行は `FormatsMarkdownText` トレイトで潰しています
- 参考: https://readouble.com/laravel/13.x/ja/mail.html （「Markdown メール」）
