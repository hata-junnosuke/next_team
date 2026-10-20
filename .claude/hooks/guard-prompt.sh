#!/usr/bin/env bash
# UserPromptSubmit フック: main の作業ツリーではループのコマンド（/epic-flow・/issue-flow・/pr-create）を止め、/issue-start を案内する
# 入口は main の /issue-start <番号> の 1 つ。ループは issue の worktree の中で回す（main ではコードを書かない: .claude/rules/common/git-workflow.md）
# 入力: stdin JSON {"prompt":"/epic-flow 25", ...}
# 出力: 止めるなら {"decision":"block","reason":"..."}。何も出さなければそのまま通る
# 判定は場所だけで行う（--git-dir と --git-common-dir が同じなら main の作業ツリー）。ブランチ名は読まない
# （issue #25 では main の作業ツリーが issue/25 に切り替わっていたので、ブランチ名では捕まらない）
set -u
INPUT="$(cat)"
PROMPT="$(printf '%s' "$INPUT" | jq -r '.prompt // ""' 2>/dev/null | head -n 1)"

# 1 行目の先頭が対象のコマンドのときだけ見る（文中で /epic-flow に触れた質問や計画は止めない）
CMD_RE='^[[:space:]]*/(epic-flow|issue-flow|pr-create)([[:space:]]|$)'
printf '%s' "$PROMPT" | grep -Eq "$CMD_RE" || exit 0

ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
DIRS="$(git -C "$ROOT" rev-parse --path-format=absolute --git-dir --git-common-dir 2>/dev/null)" || exit 0
GIT_DIR="$(printf '%s\n' "$DIRS" | sed -n 1p)"
COMMON_DIR="$(printf '%s\n' "$DIRS" | sed -n 2p)"
# 取れなかったとき・linked worktree（issue の worktree、レビュー用）のときは通す
[ -n "$GIT_DIR" ] && [ "$GIT_DIR" = "$COMMON_DIR" ] || exit 0

COMMAND="$(printf '%s' "$PROMPT" | sed -nE 's#^[[:space:]]*/([a-z-]+).*#\1#p')"
NUMBER="$(printf '%s' "$PROMPT" | sed -nE 's#^[[:space:]]*/[a-z-]+[[:space:]]+([0-9]+).*#\1#p')"
NUMBER="${NUMBER:-<番号>}"

jq -n --arg c "$COMMAND" --arg n "$NUMBER" '{
  decision: "block",
  reason: ("main の作業ツリーでは /" + $c + " は使えません（/epic-flow・/issue-flow・/pr-create は issue の worktree の中で使う）。main で /issue-start " + $n + " を打って worktree を作り、その worktree の Claude で実行してください。worktree がもうあるなら、その worktree の Claude で打ってください。")
}'
exit 0
