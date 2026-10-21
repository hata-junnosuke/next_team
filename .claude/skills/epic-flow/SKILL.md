---
name: epic-flow
description: アウターループ。エピック（タスクが複数ある issue 1 つ。例: #3 デザイン見直し）を planner に分解させ、人の承認後にタスクごとに issue-flow を回し、全部終わったら verify full と PR を出して人に最終確認を求める。issue の worktree で起動する（Orca では /issue-start が worktree の Claude に最初の指示として渡す。worktree の中なら人が打ってもよい。main の作業ツリーではフックが止める）
argument-hint: <issue 番号> [補足。例: #3]
disable-model-invocation: true
---

# /epic-flow — アウターループ

エピック: **$ARGUMENTS**

エピック = タスクが複数ある GitHub issue 1 つ。タスクの正は issue 本文のチェックリスト。ブランチは `issue/<番号>`。

## 手順

### 0. 場所を確かめる
- このスキルは `/issue-start` が作った issue の worktree（ブランチ `issue/<番号>`）で動く。main の作業ツリーでは UserPromptSubmit フック `guard-prompt.sh` が止めるが、Skill ツールから呼ばれたときは通るので、ここでも確かめる
- `git rev-parse --path-format=absolute --git-dir --git-common-dir` の 2 行が同じなら main の作業ツリー。そうなら、またはブランチが `issue/<番号>` でなければ、ブランチを作らずに止まって `/issue-start <番号>`（main で打つ）を案内する

### 1. 計画（planner）
- 通常は main の `/plan <番号>` で計画と承認が済んでから `/issue-start` している（`/issue-start` は承認済みのタスク表がなければ止まる）。issue 本文にすでに承認済みのタスク表（チェックリスト）があれば、1・2 を飛ばして 3 へ
1. `/plan <番号>` と同じ手順で planner に分解させ、リードが issue 本文に書く
2. planner が質問を返したら、**分解を進めずに人へ質問する**

### 2. 人の承認（チェックポイント 1）
- タスク表を要約して人に見せ、承認を待つ。**承認前に実装を始めない。リードが代行しない**
- 承認後、issue 本文に「（日付 人が承認）」と書く

### 3. タスクの実行（issue-flow の繰り返し）
- 最初のタスクの前に、**Orca を使用している場合**（`ORCA_WORKTREE_ID` が空でない）は、この worktree の画面を issue ごとのポートで起動して Orca のブラウザで開く（`.claude/skills/issue-flow/dev-server.md`）。`/issue-start` が起動済みならタブを開き直すだけ、止まっていれば起動し直す。Orca の外なら何もしない
- 依存関係の順に、タスクごとに `/issue-flow <番号> <タスクID>` を実行する。issue 本文でチェック済みのタスクは飛ばす（途中から再開できる）
- 並列にしてよいのは、docs/01 の担当と所有ファイルにある並列担当（api の A / B / C）と web（WA / WB / WC）だけ。**同時に実装 3 体・レビュー 4 体まで**。レビューが 4 体を超えるときは担当ごとに順番に回す
- 基盤と非同期（docs/01 の担当と所有ファイル）は直列
- 並列中、リード（あなた）は **全担当が終わるまで終了しない**（終了すると Stop フックが途中状態の全テストを回して赤になる）
- タスクが人の判断待ちになったら、issue に `requires-human` ラベルを付けて理由をコメントし、他の独立タスクは続けつつ人に報告する

### 4. 統合
- issue 本文のタスクが全部チェック済みになったら `/verify full` を実行する
- 赤ならその時点で Stop フックの検証ループが働く。緑になるまで直す（`build-error-resolver`）

### 5. 人の最終確認（チェックポイント 2）
- `/pr-create <番号>` で PR を作り、CI が緑になるのを確かめる。次を人に提示する
  - PR の URL、`make verify` と CI の結果、レビューの差し戻し回数
  - issue に書いた画面の確認項目のうち、このエピックで確認できるようになったもの
  - 人の作業が必要なこと（`.env` の反映、マイグレーションのロック）
- **`main` へのマージは人が行う**（マージで issue が閉じる）

## ハードストップ
- planner の質問が 2 往復しても解消しない → 止めて人に判断を仰ぐ
- 同一タスクが issue-flow で 2 回人の判断待ちになる → エピック全体を一時停止（`requires-human` ラベル）
