# ループの規約（常時適用。ループエンジニアリングの「ルール定義の 4 要件」）

1. **明確なゴール定義**: api のタスクの完了条件はテスト名で書く。web のタスクは「型チェックと ESLint が緑」＋ `myteam-acceptance` の手動チェック項目の番号で書く。「動くこと」は完了条件ではない。issue 本文に完了条件がないタスクは着手しない
2. **安全な作業環境**: 担当は所有ファイルとブランチの中だけで作業する。基盤（migrations、routes、TestCase、`lib/api.ts`、`components/ui`）はロック後に触らない。フックが `.env`・`vendor/`・`node_modules/`・危険な git 操作を止める
3. **機械的な成功判定**: 成功は Pint・PHPUnit・tsc・ESLint で判定する。自己申告・目視の「たぶん動く」は不可。Stop フックが終了時に強制実行する
4. **ハードストップ**: 検証ループは 5 回、レビュー差し戻しは 2 周、planner の質問往復は 2 回まで。超えたら issue に `requires-human` ラベルを付けて理由をコメントし、止まって人に報告する。無限に粘らない

## 3 層のループ
- アウターループ `/epic-flow`: エピック（issue）→ タスク分解（issue 本文のチェックリスト）→ 人の承認 → タスクごとに issue-flow → 統合 → PR → 人の最終確認とマージ
- インナーループ `/issue-flow`: implementer → tester → 3 レビュアー並列 → 差し戻し（2 周）→ 完了。**各工程は直列**
- 最内ループ Stop フック `verify-loop.sh`: api（Pint → PHPUnit）→ web（変更があれば tsc → ESLint）→ 受け入れ条件 → 赤なら block（5 回）
- チームの関門 CI（`.github/workflows/ci.yml`）: PR ごとに `make verify` を回す。差し戻しの履歴は CI の結果と PR で見る

## 人しかやらないこと
- フックと `settings.json` の変更の承認（`protect-files.sh` が承認ダイアログを出す）
- マイグレーションのロック（`.claude/.migrations-locked` を置く）
- `.env` の変更
- 計画の承認、`main` へのマージ
- 画面の手動確認（`myteam-acceptance`）

## メモリ
- タスクの状態は **GitHub Issues** が正（本文のチェックリスト、`requires-human` ラベル、PR の `Closes #<番号>`）。工程ごとには書き換えず、タスクの完了時にチェックを 1 回付けるだけ
- SessionStart フックが open の issue と直近のコミットを読み戻す
- `.claude/state/STATE.md` は 2026-10-13 に廃止（issue #4）。P0〜P5 の記録は `.claude/state/archive/STATE_P0-P5.md`（編集しない）
- 1 issue = 1 ブランチ `issue/<番号>`。並列に進めるときは issue ごとに worktree を分ける
- 差し戻し・重大指摘・想定外の挙動から得た教訓は、効かせたい場所（`CLAUDE.md`・`.claude/rules/`・スキル・docs）に **PR で直接書く**。教訓だけを貯める置き場は作らない（issue #8 で教訓の記録ファイルと専用スキルを廃止）

詳細: `docs/04_ループエンジニアリング.md`
