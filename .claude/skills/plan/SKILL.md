---
name: plan
description: 実装前の計画。planner に委譲して issue をタスク表（担当・所有ファイル・完了条件・依存）に分解し、issue 本文のチェックリストにして人が承認できる形で出す。コードを書く前に必ず使う
argument-hint: <issue 番号 または 対象。例: #3、F-20 確定通知メール>
---

# /plan — 計画

対象: **$ARGUMENTS**

タスクの正は **GitHub Issues**（issue #4 で `STATE.md` から移した）。1 issue = 1 ブランチ `issue/<番号>`。

1. issue がなければ先に `/issue-create task <概要>` で作る（背景と決定事項だけでよい。タスクは次の手順で埋める）
2. `planner` サブエージェントを起動し、対象を分解させる。渡すもの: issue 番号（`gh issue view <番号>` で本文が読める）、対象の F-xx、`docs/01` の担当と所有ファイル
3. planner の出力（issue 本文の下書き・曖昧点・見積り）を受け取り、曖昧点が残っていれば **実装に進まず人に質問する**
4. リードが下書きを issue 本文の「タスク」の見出しに書く（`gh issue edit <番号> --body-file <下書き>`）。見出しは `.github/ISSUE_TEMPLATE/task.md` のまま変えない。タスクは `- [ ] **ID** タスク` のチェックリストで、各タスクの下に担当・所有ファイル・完了条件・依存
5. 人に「このタスク表で進めてよいか」を示して止まる。承認されたら本文の「タスク」の見出しに「（日付 人が承認）」と書く。承認後は main で `/issue-start <番号>` → 作った worktree で `/epic-flow <番号>`（タスクが複数）または `/issue-flow <番号>`（1 件）へ

## 良い完了条件の 3 要素（ループエンジニアリングの「明確なゴール定義」）
- 測定可能: api はテスト名、web は tsc / ESLint の緑と、人が画面で確かめる項目
- 証明方法: `make api-test FILTER=X` が緑／`make web-typecheck` が緑
- 守る制約: 所有ファイル以外を触らない、契約（docs/01）を変えない、パッケージを足さない
