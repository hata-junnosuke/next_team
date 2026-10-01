# ループの規約（常時適用。ループエンジニアリングの「ルール定義の 4 要件」）

1. **明確なゴール定義**: api のタスクの完了条件はテスト名で書く。web のタスクは「型チェックと ESLint が緑」＋ `myteam-acceptance` の手動チェック項目の番号で書く。「動くこと」は完了条件ではない。`STATE.md` に完了条件がないタスクは着手しない
2. **安全な作業環境**: 担当は所有ファイルとブランチの中だけで作業する。基盤（migrations、routes、TestCase、`lib/api.ts`、`components/ui`）はロック後に触らない。フックが `.env`・`vendor/`・`node_modules/`・危険な git 操作を止める
3. **機械的な成功判定**: 成功は Pint・PHPUnit・tsc・ESLint で判定する。自己申告・目視の「たぶん動く」は不可。Stop フックが終了時に強制実行する
4. **ハードストップ**: 検証ループは 5 回、レビュー差し戻しは 2 周、planner の質問往復は 2 回まで。超えたら `STATE.md` の該当タスクを `requires-human` にして止まり、人に報告する。無限に粘らない

## 3 層のループ
- アウターループ `/epic-flow`: エピック → タスク分解 → 人の承認 → タスクごとに issue-flow → 統合 → 人の最終確認
- インナーループ `/issue-flow`: implementer → tester → 3 レビュアー並列 → 差し戻し（2 周）→ 完了。**各工程は直列**
- 最内ループ Stop フック `verify-loop.sh`: api（Pint → PHPUnit）→ web（変更があれば tsc → ESLint）→ 受け入れ条件 → 赤なら block（5 回）

## 人しかやらないこと
- フェーズの移行（`.claude/.phase` の書き換え）
- マイグレーションのロック（`.claude/.migrations-locked` を置く）
- `.env` の変更
- 計画の承認、`main` へのマージ
- 画面の手動確認（`myteam-acceptance`）

## メモリ
- `.claude/state/STATE.md`（タスク状態）、`loop-log.md`（差し戻しの自動記録）、`learnings.md`（`/learn` の教訓）をセッション外に永続化する。SessionStart フックが読み戻す
- 差し戻し・重大指摘・想定外の挙動があったら `/learn` で 1 行残す

詳細: `docs/04_ループエンジニアリング.md`
