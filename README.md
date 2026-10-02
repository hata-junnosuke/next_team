# myteam 企業側管理画面（第3弾: Next.js + Laravel API + 非同期処理）

企業が自社の人材プール（OB・OG／スポットワーカー）を持ち、単発の募集を出して働いてもらうまでを管理する「企業側管理画面」です。同じ要件を技術を変えて実装し直す練習の 3 回目で、今回の主題は **Job / Queue を中心とした非同期処理** です。

| 文書 | 内容 |
|---|---|
| [`../00_要件定義.md`](../00_要件定義.md) | 要件（全弾共通。4.7 が第3弾で足した非同期の要件） |
| [`docs/00_計画と決定事項.md`](docs/00_計画と決定事項.md) | 何を、なぜ、どの順でやると決めたか |
| [`docs/01_技術仕様_next.md`](docs/01_技術仕様_next.md) | 契約（テーブル・サービス・API・画面・担当分担） |
| [`docs/02_学習ポイント.md`](docs/02_学習ポイント.md) | 何をどこで学ぶか（Rails との対比つき） |
| [`docs/03_非同期処理の設計.md`](docs/03_非同期処理の設計.md) | 今回の主題。キュー・冪等性・失敗・スケジューラ・バッチ |
| [`docs/04_ループエンジニアリング.md`](docs/04_ループエンジニアリング.md) | AI エージェントのループの仕組みとゲート確認 |

---

## いまここ（2026-10-02）

**P1〜P5 の実装は終わりました。残りは人の作業です。** P6（F-25 Broadcasting、Could）は未着手です。

| 範囲 | 状態 |
|---|---|
| P1 API 骨格・P2 ドメイン・P3 フロント | 完了。`main` にマージ済み |
| P4 非同期（F-20〜F-23、F-19 のキャッシュ） | 完了。`main` にマージ済み。Horizon / Telescope（P4-01b）は 2026-10-02 に人が承認して導入済み。ただし画面での実物の確認はまだ |
| P5 仕上げ（F-24 通知履歴、この README と docs） | 完了。`main` にマージ済み（人の指示でリードがマージ） |
| P6 F-25 Broadcasting（Could） | 未着手 |
| 自動テスト | api の PHPUnit 834 件・4122 アサーションが緑（2026-10-02、`make api-test`） |

### 人の作業（残っているもの）

`.claude/state/STATE.md` の末尾「人への引き継ぎ」と同じ内容です。

1. **Horizon と Telescope を実物で確かめる（P4-01b は導入済み）**: 2026-10-02 に人が承認し、`laravel/horizon` v5.50.0 と `laravel/telescope` v5.25.0（dev）を入れた。`make horizon` は動く。まだ確かめていないのは、Horizon の画面で P4-02〜P4-05 のジョブが見えること、Redis での同時書き込み、`failed_jobs` の再実行（A-4）、Telescope と SQLite の相性（docs/03 10 章）。ローカルの DB に `telescope_entries` を作るため、先に `make fresh` を 1 回流す
2. **`api/.env` を P4 の値に切り替える**（`api/.env.example` の P4 の行。docs/01 2 章）。2026-10-02 時点の `php artisan about --only=drivers` は Queue = database、Cache = database、Mail = log で、まだ P1〜P3 の値です。この状態ではジョブは DB のキューに積まれ、メールは Mailpit ではなくログに出ます。Horizon は Redis のキューしか見ない（`api/config/horizon.php` のスーパーバイザが `connection => redis`）ので、切り替えるまでは `make horizon` を起動してもジョブを拾いません
3. マイグレーションのロック: `touch .claude/.migrations-locked`
4. `.claude/.phase` の更新（今は 0 のまま。人の判断）
5. 画面の手動確認: 下の M-1〜M-13 と A-1〜A-11 を通し、確認日の欄を記入する
6. 夜の間にリードが決めた判断の確認（`STATE.md` の「判断の確認」、docs/00 の 2.4）

### 人しかやらないこと

フェーズの移行（`.claude/.phase`）、マイグレーションのロック（`.claude/.migrations-locked`）、`.env` の変更、計画の承認、`main` へのマージ、画面の手動確認。いつ何をするかは [`docs/00`](docs/00_計画と決定事項.md) の 6 章にあります。

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
| テスト | api は PHPUnit。web は型チェックと ESLint、画面は下の手動チェックリスト |
| 整形 | api は Pint、web は Prettier（編集のたびにフックが自動整形） |
| AI ハーネス | `.claude/`（agents 11 / skills 12 / rules 8 / hooks 8 / contexts 3 / state） |

```
next_team/
├── CLAUDE.md          絶対ルールと現在地の見方
├── Makefile           コマンドの入口（make help）
├── compose.yaml       redis / mailpit
├── docs/              00 計画 / 01 契約 / 02 学習 / 03 非同期 / 04 ループ
├── .claude/           hooks（強制）/ agents / skills（手順）/ rules / state（メモリ）
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

8000 は第1弾、8001 は第2弾、3000 は別プロジェクトが使うので避けています。`make ports` で、8002 と 3002 をどのフォルダのプロセスが使っているかを確かめられます。

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

このリポジトリを作ったマシンでは、ここまで済んでいます（2026-10-02 に `make verify-full` が緑。Pint・PHPUnit 827 件・tsc・ESLint・Prettier・`next build`）。

### 毎回

ターミナルを 4〜5 つ使います。2026-10-02 に下の順で実際に起動し、curl でログイン（CSRF Cookie 204 → `/login` 200 → `/api/user` 200）、未ログインの `/api/user` が 401、未ログインの `/dashboard` が `/login` へ 307 で戻されることを確かめました。

```bash
make up            # redis / mailpit（Docker。起動済みなら不要。make ps で確認）
make fresh         # 初回とデータを作り直したいときだけ。DB を作り直してシード
make api-serve     # ターミナル 1: http://localhost:8002
make web-dev       # ターミナル 2: http://localhost:3002
make horizon       # ターミナル 3: キューワーカー（Horizon。api/.env が P4 の値＝Redis のときにジョブを拾う）
make schedule      # ターミナル 4: スケジューラ（F-22 の前日リマインド。毎日 18:00）
```

- ログインは http://localhost:3002/login で **`admin@example.com` / `password`**（`make fresh` のシード。企業「株式会社サンプル」の管理者）
- `api/.env` がまだ P1〜P3 の値（`QUEUE_CONNECTION=database`）のときは、`make horizon` の代わりに `cd api && php artisan queue:work --queue=mails,default` を動かします。Horizon は Redis のキューしか処理しないためです。どちらのワーカーも動かさないと、確定通知・中止通知・リマインド・CSV の非同期出力はキューに積まれたまま実行されません
- Horizon と Telescope の画面は、管理画面にログインした同じブラウザで開きます（ローカル環境かつログイン済み管理者だけ。未ログインは 403）。Telescope の記録テーブル `telescope_entries` は `make fresh` で作られます
- メールは Mailpit（http://localhost:8025）で見ます。`api/.env` が P4 の値（`MAIL_MAILER=smtp` / `MAIL_PORT=1025`）になっている必要があります
- `make schedule` の代わりに、リマインドは `cd api && php artisan app:send-reminders --date=2026-10-03` で手動実行できます（`php artisan schedule:list` に `0 18 * * *` で出ます）

### よく使うコマンド

```bash
make help                       # 一覧
make verify                     # Stop フックが回すものと同じ検証
make api-test FILTER=Foo        # テストを絞って実行
make fresh                      # DB を作り直してシード
make verify-full                # verify + Prettier の整形チェック + next build
make ports                      # 8002 / 3002 をどのフォルダのプロセスが使っているか
make down                       # redis / mailpit を止める（データは残る）
```

---

## 手動チェックリスト

web の自動テストは書かない方針です（決定9）。**画面と、非同期の実物（Redis・Horizon・Mailpit）は人が手で確かめます。** 確かめたら日付を書きます。対応する API の自動テストは `.claude/skills/myteam-acceptance/SKILL.md` の表にあります。

A-1〜A-4・A-9・A-11 の「Horizon」「Telescope」の部分は、P4-01b（2026-10-02 導入）の後まだ誰も実物で確かめていません。`api/.env` を P4 の値に切り替え、`make fresh` を流してから確かめます。アクセス制御（A-11 の 403）は `api/tests/Feature/HorizonAccessTest.php` が自動テストで示しています。

確認の前に: `make ports` で 8002 と 3002 が `next_team` のプロセスであること。同じブラウザで第2弾（`localhost:8001`）を開いていると `XSRF-TOKEN` Cookie が上書きし合うので、閉じるか別プロファイルを使います。

### 前半（P3 の終わり）— 動線 10 ステップ

| # | 手順 | 期待 | 確認日 |
|---|---|---|---|
| M-1 | ログインする。誤ったパスワードも試す。未ログインで `/dashboard` を開く | ダッシュボードへ。エラーが日本語。`/login` へ戻される | |
| M-2 | 拠点「東京倉庫」を登録する。名称を空でも試す | 一覧に出る。エラーが入力欄の下に出る | |
| M-3 | ワーカーを 4 名登録し、1 名を無効にする。メールを空でも試す。氏名で検索する | 有効/無効が出る。**メール必須のエラー**。絞り込める | |
| M-4 | 募集「9/20 9:00-17:00 ピッキング 3 名 時給 1,300 円 交通費 500 円」を作る。終了 < 開始、最低賃金未満も試す | 詳細に出る。それぞれエラー | |
| M-5 | 有効ワーカー 3 名を応募させる。同じワーカーをもう一度応募させようとする | 選択肢に無効ワーカーと応募済みワーカーが出ない | |
| M-6 | 3 名を承認する。4 人目（新規登録して応募）を承認する | 確定 3/3。「定員に達しているため承認できません。」 | |
| M-7 | 1 名を企業都合でキャンセルし、4 人目を承認する | 1 枠空いて承認できる。理由の選択が必須 | |
| M-8 | 確定 3 名に勤怠 9:00-17:00 休憩 60 分を入力する。休憩 600 分も試す | 行に反映。休憩超過エラー。表が折り返して崩れない | |
| M-9 | 報酬を見る | 7 時間 × 1,300 + 500 = **9,600円** | |
| M-10 | 9 月の月次集計を開き、CSV をダウンロードする | ワーカー別に 3 行。CSV が Excel で開ける | |
| M-11 | ダッシュボードを見る | 今日以降の募集、充足率、未処理の応募数 | |
| M-12 | 募集を締切・中止・複製する | 締切後も応募中は承認できる。中止で確定が企業都合キャンセルになる | |
| M-13 | ログアウトし、ブラウザの戻るボタンを押す | `/login` に戻り、管理画面が見えない | |

### 後半（P4・P5 の終わり）— 非同期

| # | 手順 | 期待 | 確認日 |
|---|---|---|---|
| A-1 | 応募を承認する | 画面はすぐ返る。Mailpit に確定通知（日時・拠点・職種・時給）。Horizon の `mails` に完了ジョブ | |
| A-2 | `make horizon` を止めて承認 → Horizon を起動 | 画面は返る。起動した後にメールが届く | |
| A-3 | 確定 3 名の募集を中止する | 中止通知が 3 通。Horizon に 3 ジョブ | |
| A-4 | `docker compose stop mailpit` → 募集を中止 → 失敗を待つ → `docker compose start mailpit` → Horizon から再実行 | `failed_jobs` に残る。通知履歴が「失敗」。再実行で届く | |
| A-5 | 翌日勤務の確定応募を作り `cd api && php artisan app:send-reminders` を 2 回実行 | リマインドは 1 通だけ | |
| A-6 | `make schedule` を動かし、`MYTEAM_REMINDER_TIME` を数分後にして待つ | 時刻になると届く | |
| A-7 | 月次集計の画面で CSV を非同期で生成する | すぐ「受付」。進捗が進み「完了」。Mailpit に完了通知。ダウンロードできる | |
| A-8 | ダウンロード URL の `signature` を 1 文字変えて開く | 403 | |
| A-9 | ダッシュボードを開く → 応募を承認 → 開き直す | 充足率が変わる。Telescope に cache の hit / forget | |
| A-10 | 通知履歴の画面（`/notifications`）を開き、種別と状態で絞り込む | A-1〜A-7 の送信が成功／失敗つきで並ぶ | |
| A-11 | ログアウトして http://localhost:8002/horizon を開く | 403 | |

---

## AI エージェント組織（`.claude/`）の使い方

| やりたいこと | コマンド |
|---|---|
| フェーズ 1 つ分をまとめて進める | `/epic-flow p1-skeleton`（人が起動。計画を承認してから実装が始まる） |
| タスク 1 件を実装 → テスト → レビューまで回す | `/issue-flow <タスクID>` |
| 実装の前に計画だけ立てる | `/plan F-20 確定通知メール` |
| 検証する | `/verify`（quick）/ `/verify pre-commit` / `/verify full` |
| レビューだけする | `/code-review` |
| 教訓を残す | `/learn <出来事>` |

応答を終えようとするたびに Stop フックが検証を回し、赤なら差し戻します（5 回で打ち切り）。仕組みは [`docs/04`](docs/04_ループエンジニアリング.md)。

---

## 設計で迷った点と判断

実装前の判断は [`docs/00`](docs/00_計画と決定事項.md) の 2 章（決定 1〜34）と [`docs/01`](docs/01_技術仕様_next.md) の 12 章にあります。ここには **実装してみて迷い、決め直したもの** を並べます。実装中に足した決定は docs/00 の 2.4（決定 35〜）に番号つきで残しています。

### 1. 定員チェックは SQLite の `IMMEDIATE` で直列化する（P2-01・P2-04）

- どこ: `api/config/database.php`（`transaction_mode => 'IMMEDIATE'`、`journal_mode => 'wal'`、`busy_timeout => 5000`）、`api/app/Services/ApplicationService.php`
- 迷った点: SQLite は `lockForUpdate()`（Rails の `lock`。`SELECT ... FOR UPDATE`）を無視します。2 つの承認が同時に来ると、両方が「確定数 2 / 定員 3」を読んで両方確定し、定員を超えます
- 判断: トランザクションの開始時に書き込みロックを取る `BEGIN IMMEDIATE` にしました。後から来た承認は、先の承認のコミットを待ってから確定数を数えます。P2-01 で 2 プロセスを並べ、後の方が約 2 秒待つことを確かめました。`lockForUpdate()` も意図を示すために書き、ロックの順は「募集 → 応募 → 確定数」にそろえています（MySQL に移したときにデッドロックしにくい順）
- 落とし穴: `transaction_mode` は PHP 8.4 以上でしか `BEGIN IMMEDIATE` にならず、8.3 では黙って普通の `BEGIN`（DEFERRED）になります。`api/composer.json` を `^8.4` にしたのはこのためです
- 参考: https://readouble.com/laravel/13.x/ja/database.html

### 2. メールのジョブは `$tries = 0` + `$maxExceptions = 3` + `retryUntil()`（P4-03）

- どこ: `api/app/Jobs/SendApplicationConfirmedMail.php`、`SendPostingCancelledMail.php`、`SendShiftReminderMail.php`
- 迷った点: 最初は `$tries = 3`（Rails の `retry_on attempts: 3`）でした。ところが流量制限のジョブミドルウェア `RateLimited` でキューへ戻された回も「1 回の試行」に数えられます。流量の上限に 3 回当たっただけで、メールを 1 度も送ろうとしないまま `failed()` に入り、`notification_logs` に「届いていない」記録が残りませんでした（tester が再現）
- 判断: 試行回数では打ち切らず（`$tries = 0` は無制限）、「送信の例外が 3 回」（`$maxExceptions`）か「積んでから 60 分」（`retryUntil()`、`MYTEAM_MAIL_RETRY_UNTIL_MINUTES`）で打ち切ります。期限切れで `failed()` に入ったときは対象を読み直して失敗の記録を作ります（`NotificationLogService::recordFailure()`）
- 参考: https://readouble.com/laravel/13.x/ja/queues.html （「最大試行回数／タイムアウト値」「レート制限」）

### 3. `dedupe_key` は全社で一意のまま。代わりに `claim()` で企業を確かめる（P4-01）

- どこ: `api/database/migrations/2026_10_03_100000_create_notification_logs_table.php`、`api/app/Services/NotificationLogService.php`
- 迷った点: `notification_logs` の宛先と対象はポリモーフィック（Rails の `belongs_to :recipient, polymorphic: true`）なので、他のテーブルのような「保存時に親が自社か」の検査が効きません。security-reviewer の重大指摘で、他社の応募を対象に `claim()` されると、他社の宛先（個人情報）が自社の履歴に入り、さらに全社で一意の `dedupe_key` を先取りして他社の通知を止められることが分かりました
- 判断: UNIQUE を「企業ごと」（`company_id, dedupe_key`）に変える案もありましたが、キーには応募 ID や出力 ID が入っていて元々全社で重なりません。問題は UNIQUE の範囲ではなく「他社のものを対象にできること」なので、`claim()` の入口で、宛先と対象が未保存・`company_id` なし・他社のときは `LogicException` にしました。`error` 列には例外の生のメッセージ（SQL のバインド値や SMTP のユーザー名を含みうる）を残さず固定の文言にしています

### 4. 同時に 2 本走った通知ジョブは「少なくとも 1 回」（at-least-once）で受け入れる（P4-01・P4-02）

- どこ: 各メールのジョブの `middleware()`（`WithoutOverlapping(...)->dontRelease()`）、[`docs/03`](docs/03_非同期処理の設計.md) の 3.3・4.3
- 迷った点: `dedupe_key` の UNIQUE が防ぐのは「送信済みの後の 2 通目」だけです。2 本のジョブが同時に同じ送信待ちの行を受け取ると、両方が送ります（database-reviewer の指摘）。DB で厳密に止めるには `claimed_at` 列を足して「条件付き UPDATE の影響行数が 1 のときだけ送る」形にします
- 判断: 人が不在の夜間だったので、新しい列は足さず、ジョブミドルウェア `WithoutOverlapping`（キャッシュのロック）で抑え、保証の範囲を at-least-once と docs/03 に明記しました。後から新しいマイグレーションで足せます（人の確認待ち）。2 本目は `dontRelease()` で捨てます。後回しにすると、その回も試行に数えられ、送信済みなのに「失敗」が残るためです

### 5. 止まった「生成中」の出力は、30 分を過ぎたら次の依頼で failed にする（P4-05）

- どこ: `api/app/Services/ReportExportService.php`、`api/app/Jobs/FinalizeReportExport.php`、`MYTEAM_REPORT_EXPORT_STALE_MINUTES`
- 迷った点: 同じ月の出力が生成中なら 422「同じ月の出力が生成中です。」にしています。ところがワーカーが落ちると、ロックの期限（`expireAfter`）がキューの `retry_after` より長い場合、再実行がロックに当たって黙って捨てられ、どの `failed()` も呼ばれません。出力が processing のまま残り、その月は永遠に 422 になります（database-reviewer の重大指摘）
- 判断: 期限を「ジョブの `$timeout`（50 秒）< `expireAfter`（60 秒）< キューの `retry_after`（90 秒）」の順にそろえ、さらに逃げ道として、30 分を過ぎた生成中は次の依頼のときに failed にしてバッチをキャンセルします。同時の依頼は企業の行を `lockForUpdate()` してから生成中を確かめます
- 補足: processing への切り替えは `Bus::batch()->before()` で行います。sync キュー（テスト）では `dispatch()` の中で `then` まで走り、しかもバッチ全体が 1 つのトランザクションに入るため、`catch` で入れた failed が巻き戻ることが分かったからです
- 参考: https://readouble.com/laravel/13.x/ja/queues.html （「ジョブバッチ」「重複ジョブの防止」）

### 6. ダッシュボードのキャッシュは「5 分」と「翌日 0:00」の早い方で切れる（P4-07）

- どこ: `api/app/Services/DashboardService.php`（`Cache::remember`。Rails の `Rails.cache.fetch`）、`api/app/Observers/DashboardCacheObserver.php`
- 迷った点: ダッシュボードは「今日以降の募集」を出すので、0:00 をまたぐと昨日の募集が残ります。キーに日付を入れる案は、`forget()` がどの日のキーを消すかを計算する必要があり、0:00 をまたいだ破棄で前日のキーが残るので採りませんでした
- 判断: 有効期限を「`MYTEAM_DASHBOARD_CACHE_SECONDS`（既定 300 秒）後」と「翌日 0:00」の早い方にしました。破棄は Observer がコミット後（`ShouldHandleEventsAfterCommit`。Rails の `after_commit`）に行い、対象は `Application`・`JobPosting` に加えて `Location`（拠点名を一覧に出しているので。tester が古い拠点名が残るのを再現）です。キーは `dashboard:{company_id}` で、消すのは変更されたモデルの企業のキャッシュです
- 参考: https://readouble.com/laravel/13.x/ja/cache.html 、https://readouble.com/laravel/13.x/ja/eloquent.html （「オブザーバ」）

### 7. 署名付き URL はメールに入れない（P4-05）

- どこ: `api/app/Http/Resources/ReportExportResource.php`（`URL::temporarySignedRoute()`）、`api/routes/api.php` の `report-exports.download`（`signed` ミドルウェア）、`api/app/Notifications/ReportExportReady.php`
- 迷った点: 署名付き URL（Rails の `signed_id` + `expires_in` に近い）は「URL を持っていること自体が認可」です。完了通知のメールに入れると、30 分で切れるリンクがメールに残り続け、転送されれば他人もダウンロードできます
- 判断: URL は `GET /api/report-exports/{id}` を取得するたびに 30 分の期限で作り直し、`completed` のときだけ返します。メールは「完了した」ことだけを知らせ、ダウンロードは管理画面（`/reports/exports`）から行います。同じ理由で、ワーカー宛てのメールからは管理画面へのリンクを外し、`x-mail::message`（ヘッダーに `APP_URL` へのリンクが入る）ではなく `x-mail::layout` を直接使っています
- 参考: https://readouble.com/laravel/13.x/ja/urls.html （「署名付きURL」）

### 8. 出力一覧の自動更新を外した（P4-06）

- どこ: `web/app/(admin)/reports/exports/page.tsx`、`web/components/reports/ExportTable.tsx`
- 迷った点: リードの指示で、生成中の出力があれば画面が数秒ごとに自動で読み直す仕組みを入れていました
- 判断: 「状態が変わったら画面が自動で切り替わる」は F-25（Broadcasting、Could）の範囲で、Must の F-23 で入れると Could の先取りになるので外し、「最新の状態に更新」リンクにしました。Server Component なので、開き直せば最新の状態を取り直します。生成中・失敗の判定は `web/lib/status.ts` の `isInProgressExportStatus()` / `isFailedExportStatus()` の 2 つだけにしています（業務ルールを web に書かない）

### 9. Markdown メールの差し込みは無害化を足す（P4-02）

- どこ: `api/app/Mail/Concerns/FormatsMarkdownText.php`、`api/resources/views/mail/*.blade.php`
- 迷った点: Markdown メールの `{{ }}` は HTML しか無害化しません。ワーカーの氏名に `[x](https://evil)` を入れるとリンクになります
- 判断: `Markdown::withSecuredEncoding()` を使い、それでも残る表の区切り `|` と改行は `FormatsMarkdownText` トレイトで潰しています
- 参考: https://readouble.com/laravel/13.x/ja/mail.html （「Markdown メール」）

---

## 次回やり直すなら変えること

| # | 変えること | なぜ（今回起きたこと） |
|---|---|---|
| 1 | **git worktree の `vendor` をシンボリックリンクにしない。worktree ごとに `composer install` するか、最初から worktree 用の autoload を用意する** | P2 の並列（A / B / C）で `api/vendor` をシンボリックリンクにしたところ、Composer の autoload の基準ディレクトリがメインのフォルダになり、`App\` `Tests\` `Database\` のクラスがメイン（や他の worktree）から読まれていました。worktree で「緑」でも worktree のコードを検証していなかったことになります。担当が気づき、worktree の `app/` `tests/` `database/` を先に探す bootstrap を `--bootstrap` で渡して回避し、最終判定は epic ブランチへ統合した後のメインで行いました。web の worktree では `node_modules` のリンクで足りましたが、`.next/types` が生成されないので `next typegen` を先に回す必要がありました |
| 2 | **テスト名に英大文字を入れない、を最初の規約にする** | Pint の `php_unit_method_casing` がテスト名を snake_case に直し、`test_Acceptヘッダなしでも_JSONで返る` → `jso_nで返る`、`DBが` → `dbが`、`Context` → `_context` のように崩しました。英字は小文字で `_` 区切り（`test_ジョブはcontextから企業を引き継ぐ`）にしています。計画のテスト名にも英大文字が混ざっていたので、planner のテンプレートの段階で直す |
| 3 | **日付に依存するテストは最初から `travelTo()` で固定する** | P4-04 で「勤務日が過ぎたら送らない」を足すと、固定の日付（2026-10-10 など）を使うテストが、実際の日付がその日を過ぎた時点で赤になる作りでした。`$this->travelTo()`（Rails の `travel_to`）で時刻を固定するのを tdd-workflow の既定にする |
| 4 | **範囲外のページの空表示の文言を分ける** | 一覧で `?page=99` のように範囲外を開くと、データがあっても「拠点がまだ登録されていません。」「通知の履歴はまだありません。」のように「まだない」と出ます。`meta.total` が 0 より大きいのに `data` が空なら「このページには項目がありません」と出すか、最後のページへ誘導する |
| 5 | **ファクトリの `fake()->optional()` を、期待値に関わる列で使わない** | 「送らなければ null」を期待するテストが、Faker の乱数で 2 回に 1 回だけ赤になりました（P2-04 / P2-06）。「再実行で緑」は合格にせず原因を調べ、テストで列を明示して作る形に直しました |
| 6 | **パッケージの導入は人がいる時間に前倒しする** | Horizon / Telescope は P4 の最初に入れる計画でしたが、人が不在の夜間だったので後回しにしました（P4-01b）。その結果、P4 の「Horizon の画面で見える」「Telescope で cache を見る」の確認がすべて最後に残りました。結局、導入は P5 の最後（2026-10-02 の朝に人が承認した後）になり、画面での確認は導入後もまだ残っています。フェーズの開始前に、要るパッケージの承認を人にまとめて取る |
| 7 | **`.claude/.phase` をフェーズに合わせて進める** | 今回は人の判断で 0 のまま進めたので、Stop フックの「必須テストファイルの存在検査」が一度も効かず、リードが P2-07 と P4-05 の後に `ls` で確かめました。機械的な判定を手作業に戻した形です |
| 8 | **手動確認をフェーズの終わりごとに行う** | M-1〜M-13、A-1〜A-11 はすべて最後にまとめる形になりました。画面の崩れや実物の Redis・Mailpit での差は手動確認でしか見つからないので、まとめるほど見つかるのが遅くなる |
| 9 | **ポリモーフィックな関連を持つテーブルには、最初から企業の検査を計画に入れる** | `notification_logs` の他社チェック漏れ（P4-01 の重大指摘）は、「親が自社か」を保存時に確かめる既存の仕組みがポリモーフィックには効かないことから起きました。docs/01 の契約の段階で書いておけた |

第4弾の候補は [`docs/00`](docs/00_計画と決定事項.md) の 10 章にあります。
