# learnings — 教訓の蓄積（Reflexion / ECC の instinct 相当）

`/learn <出来事>` で追記する。信頼度は 0.3（1 回観測）〜 0.9（何度も再現）。各フェーズの終わりに `doc-updater` が docs/02・docs/04 へ昇格させる。

書式: `- YYYY-MM-DD | フェーズ | api / web / loop | 何が起きたか → 教訓 | 信頼度 0.x | 根拠`

## 第2弾から引き継いだ教訓（`../ecc_team/.claude/state/learnings.md` のうち第3弾でも効くもの）

- 2026-09-14 | 第2弾 | api | `php artisan test --compact` と Pint は非 TTY（フック内）では JSON を出力する → フックは jq で整形する | 信頼度 0.7 | verify-loop.sh の format_phpunit / format_pint
- 2026-09-14 | 第2弾 | api | Fortify の throttle:login は 6 回目に 429 を返す（リダイレクトではない） → スロットルのテストは assertStatus(429) | 信頼度 0.7 | 第2弾 LoginTest
- 2026-09-14 | 第2弾 | api | `Model::create()` の戻り値はメモリ上に DB default の値を持たない → モデルの `$attributes` で既定値を DB と揃える | 信頼度 0.7 | docs/01 4.3
- 2026-09-14 | 第2弾 | api | SQLite の LIKE は既定の ESCAPE 文字がない → `LIKE ? ESCAPE '!'` を明示する（F-04 の氏名検索） | 信頼度 0.7 | 第2弾 Worker::scopeNameLike
- 2026-09-14 | 第2弾 | api | date の列に `whereBetween([月初, 月末])` を使うと月末日が漏れる → 日付範囲は半開区間で書く | 信頼度 0.9 | docs/01 4.6
- 2026-09-15 | 第2弾 | loop | 実装 3 + レビュー 9 を同時に走らせてセッション上限に達した → 同時に実装 3・レビュー 4 まで | 信頼度 0.9 | rules/common/agents.md
- 2026-09-15 | 第2弾 | loop | tester と implementer が同じテストファイルを並行編集して手戻り → issue-flow の工程は直列 | 信頼度 0.7 | skills/issue-flow
- 2026-09-15 | 第2弾 | web | HTTP テストでは検出できないレイアウト崩れ（バッジとボタンの折り返し）があった → セル・バッジ・ボタンに whitespace-nowrap。最終確認は実ブラウザ | 信頼度 0.8 | docs/01 7.5
- 2026-09-28 | 第2弾 | api | テストが確率的に赤（Faker の ja_JP の人名が assertDontSee と衝突） → phpunit.xml で APP_FAKER_LOCALE=en_US に固定。「再実行で緑」は原因調査の対象 | 信頼度 0.9 | docs/01 9.2
- 2026-09-29 | 第2弾 | loop | 手動確認で別の弾のサーバー（同じポート）を見ていた → 弾ごとにポートを変える。`make ports` と SessionStart フックで cwd を確認 | 信頼度 0.9 | Makefile、session-context.sh
- 2026-09-15 | 第2弾 | loop | リードを親ディレクトリで起動したためフックが一切効かなかった → 必ず `next_team/` で起動する。SessionStart フックが起動場所を表示する | 信頼度 0.9 | docs/04 8 章

## 第3弾の教訓

- 2026-10-01 | フェーズ 0 | loop | bash で `"$VAR（"` のように変数名の直後に日本語が続くと、変数名の一部と解釈されて unbound variable になった → フックでは日本語の直前の変数を必ず `${VAR}` と書く | 信頼度 0.7 | session-context.sh の初回実行
- 2026-10-01 | フェーズ 0 | loop | ポート 3000 は別プロジェクトの Docker コンテナが使っていた → web は 3002、api は 8002。Sanctum の stateful ドメインもこれに合わせる | 信頼度 0.7 | `make ports`、docs/01 1 章
- 2026-10-01 | フェーズ 0 | api | テスト名に英大文字と日本語を混ぜる（`test_Acceptヘッダなしでも_JSONで返る`）と、Pint の snake_case 変換が `jso_nで返る` のように崩した → テスト名の英字は小文字にして `_` で区切る | 信頼度 0.7 | G-6 の tester
- 2026-10-01 | フェーズ 0 | loop | SubagentStop と Stop は緑のとき痕跡を残さないので「発火したか」が画面では分からない → `~/.claude/projects/<プロジェクト>/<セッションID>/subagents/*.jsonl` の `"hookEvent":"SubagentStop"` を数えて確かめる（G-6 では 5 体すべて 1 回ずつ） | 信頼度 0.9 | ゲート G-6
- 2026-10-01 | フェーズ 0 | api | `config/cors.php` がない間はフレームワーク既定の CORS（`api/*` に `*`）が効く。`api:` ルーティングを足した時点で有効になる → P1 で Sanctum を入れる前に `config/cors.php` を公開して `FRONTEND_URL` だけに絞る | 信頼度 0.7 | G-6 の security-reviewer
- 2026-10-01 | フェーズ 0 | loop | `guard-bash.sh` はコマンド文字列全体を grep するので、記録文やコミットメッセージに禁止コマンドの名前を書いただけでも拒否される → 文中では「git の強制リセット」のように言い換える | 信頼度 0.9 | ゲートの記録を書く Bash が拒否された
- 2026-10-01 | フェーズ 0 | loop | 担当はバックグラウンドで動くので、リードは待つたびに応答を終え、そのたびに Stop フックが全テストを回していた（G-6 では偶然ずっと緑だった） → SubagentStart で `.claude/.running/<agent_id>` に印を置き、印がある間は検査を飛ばす。最後の担当の終了後に必ず検査される | 信頼度 0.9 | subagent-start.sh、verify-loop.sh (0.5)
- 2026-10-01 | フェーズ 0 | loop | 「検査を飛ばす印」は抜け道になりやすい（印を置くだけで逃れられる、timeout で印が残る、圧縮で印が消える、黙って飛ばすと気づけない） → 数えるのは 60 分以内の通常ファイルだけ、消すのが既定で差し戻すときだけ置き直す、compact では消さない、飛ばしたら systemMessage を出す | 信頼度 0.9 | security-reviewer の指摘（推奨 4 件）
- 2026-10-01 | フェーズ 1 | api | `SetCurrentCompany` を middleware priority に入れないと `SubstituteBindings` が先に動き、グローバルスコープが `CurrentCompany::id()` で例外 → 他社 ID が 404 でなく 500。TestCase の setUp で `CurrentCompany::set()` 済みなのでテストは偶然緑になる → `prependToPriorityList(before: SubstituteBindings)` で並べ、テストでは Context を消してから叩く | 信頼度 0.9 | P1-01 tester の TenantScopeTest
- 2026-10-01 | フェーズ 1 | api | Fortify は `features=[]` でも `user/confirm-password` 系を登録し、429 は英語 → `Fortify::ignoreRoutes()` で login / logout だけを自前登録、`ThrottleRequestsException` を日本語の JSON に | 信頼度 0.8 | P1-01 security / code レビュー
- 2026-10-01 | フェーズ 1 | api | SQLite は外部キーに索引を自動で張らず、`foreignId()->constrained()` も INDEX を作らない（MySQL の InnoDB とは違う）→ `company_id` には `->index()` を明示する。テスト名に英大文字を入れると Pint が `_inde_x` のように崩すので小文字で書く | 信頼度 0.9 | P1-02 code-reviewer
- 2026-10-01 | フェーズ 1 | api | シーダーに雛形の `WithoutModelEvents` があると `BelongsToCompany` の creating が動かず company_id が埋まらない → 外す | 信頼度 0.9 | P1-02 implementer
- 2026-10-01 | フェーズ 1 | web | React 19 の `<form action>`（useActionState）は終了時にフォームをリセットするので 422 で入力が消える → FormState に `values` を持たせ `defaultValue={state.values?.x ?? 元の値}`。Laravel のセッション Cookie 名は `Str::slug(APP_NAME)` で `next-team-session`（アンダースコアではない） | 信頼度 0.9 | P1-03 レビュー
- 2026-10-01 | フェーズ 2 | api | SQLite の `transaction_mode=IMMEDIATE` は PHP 8.4 以上でしか `BEGIN IMMEDIATE` にならない（8.3 では黙って DEFERRED）。また date cast の列はモデル経由だと `Y-m-d 00:00:00` で保存され、文字列比較の半開区間から漏れる → composer.json を `^8.4`、`work_date` は set の Attribute で `Y-m-d` に | 信頼度 0.9 | P2-01 database-reviewer
- 2026-10-01 | フェーズ 2 | api | `transitionTo()` がルートモデルバインディングで読んだ古いインスタンスで判定すると、中止で却下済みになった応募を承認できてしまう → 先頭で `refresh()`、サービスは DB::transaction の中で呼ぶ | 信頼度 0.8 | P2-01 code-reviewer
- 2026-10-01 | フェーズ 2 | loop | git worktree に `api/vendor` をシンボリックリンクで置くと、Composer の `$baseDir` がメインになり App\ / Tests\ / Database\ のクラスはメイン（や他の worktree）から読まれる。worktree で「緑」でも worktree のコードを検証していない → worktree ごとの bootstrap（worktree の app/ tests/ database/ を先に探す autoloader）を `--bootstrap` で渡す。最終判定は epic ブランチへ統合後のメインで行う | 信頼度 0.9 | P2-02 / P2-05 の担当が発見
