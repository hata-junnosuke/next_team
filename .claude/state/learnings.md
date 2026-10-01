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
