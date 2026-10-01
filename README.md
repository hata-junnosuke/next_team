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

## いまここ（2026-10-01）

**P0 の途中です。業務コードはまだ 1 行もありません。** フォルダ、`api/` と `web/` の雛形、ハーネス（`.claude/`）、ドキュメントが揃ったところです。

### 次にやること

1. **`next_team/` で Claude Code を起動し直す**（親フォルダで起動したセッションではフックが効きません）
   ```bash
   cd ~/Desktop/Study/myteam_practices/next_team
   claude
   ```
   起動直後に「## next_team セッション開始情報」が出れば、フックが読まれています。
2. **docs/00 の 2.3（質問せずに決めた設計判断 14 件）に目を通す**。違うものがあれば、ここで直します
3. 初回コミットは 2026-10-01 に作成済みです（G-1）
4. **ゲート確認**: [`docs/04`](docs/04_ループエンジニアリング.md) の 7 章の G-2〜G-6。わざと赤にして差し戻されることを確かめます
5. ゲートが通ったら `echo 1 > .claude/.phase` して `/epic-flow p1-skeleton`

### 人しかやらないこと

フェーズの移行（`.claude/.phase`）、マイグレーションのロック（`.claude/.migrations-locked`）、`.env` の変更、計画の承認、`main` へのマージ、画面の手動確認。いつ何をするかは [`docs/00`](docs/00_計画と決定事項.md) の 6 章にあります。

---

## 構成

| 項目 | 内容 |
|---|---|
| `api/` | Laravel 13.34 / PHP 8.4。JSON API 専用 |
| `web/` | Next.js 16.3 / React 19 / TypeScript / Tailwind CSS 4（App Router） |
| DB | SQLite（`api/database/database.sqlite`。テストは in-memory） |
| 認証 | Sanctum SPA 認証（Cookie + CSRF）＋ Fortify ヘッドレス（P1 で導入） |
| キュー・キャッシュ | Redis（Docker）＋ Horizon（P4 で導入） |
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
| Horizon（P4 から） | http://localhost:8002/horizon |
| Telescope（P4 から） | http://localhost:8002/telescope |

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

このリポジトリを作ったマシンでは、ここまで済んでいます（`make verify` は 2026-10-01 に緑）。

### 毎回

ターミナルを 3 つ（P4 からは 4〜5 つ）使います。

```bash
make up            # redis / mailpit（起動済みなら不要。make ps で確認）
make api-serve     # ターミナル 1: http://localhost:8002
make web-dev       # ターミナル 2: http://localhost:3002
make horizon       # ターミナル 3: キューワーカー（P4 から）
make schedule      # ターミナル 4: スケジューラ（P4 の F-22 から）
```

P1 が終わるまでは、画面は Next.js の初期ページ、API は Laravel の初期ページが出るだけです。ログインは P1 の後に `admin@example.com` / `password`（`make fresh` でシード）。

### よく使うコマンド

```bash
make help                       # 一覧
make verify                     # Stop フックが回すものと同じ検証
make api-test FILTER=Foo        # テストを絞って実行
make fresh                      # DB を作り直してシード
make down                       # redis / mailpit を止める（データは残る）
```

---

## 手動チェックリスト

web の自動テストは書かない方針です（決定9）。**画面と、非同期の実物（Redis・Horizon・Mailpit）は人が手で確かめます。** 確かめたら日付を書きます。対応する API の自動テストは `.claude/skills/myteam-acceptance/SKILL.md` の表にあります。

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
| A-10 | 通知履歴の画面（Could） | A-1〜A-7 の送信が成功／失敗つきで並ぶ | |
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

実装前の判断は [`docs/00`](docs/00_計画と決定事項.md) の 2 章（決定 1〜34）と [`docs/01`](docs/01_技術仕様_next.md) の 12 章にあります。**P5 で、実装してみて分かったことをここにまとめます。**

## 次回やり直すなら変えること

**P5 で書きます。** 候補は [`docs/00`](docs/00_計画と決定事項.md) の 10 章にあります。
