#!/usr/bin/env bash
# SubagentStart フック: 担当（サブエージェント）が動いている印を .claude/.running/<agent_id> に置く。
#   印がある間、リードの Stop フック（verify-loop.sh）は検査を飛ばす。
#   理由: 担当はバックグラウンドで動くので、リードは「待つ」ために応答を終える。そのたびに全テストを回すと、
#         TDD の途中の赤で差し戻され、検証ループの 5 回を無駄に使う（2026-10-01 のゲート G-6 で気づいた）。
#   印を消すのは subagent-check.sh（SubagentStop で担当の終了を通すとき）と session-context.sh（起動時）。
# 入力: stdin JSON {"agent_id":"...","agent_type":"..."}
set -u
ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
DIR="$ROOT/.claude/.running"
INPUT="$(cat)"
ID="$(printf '%s' "$INPUT" | jq -r '.agent_id // empty' 2>/dev/null | tr -cd 'A-Za-z0-9_-')"
# agent_id が取れないときも印は置く（消す側は「いちばん古い印」を 1 つ消す）
[ -n "$ID" ] || ID="unknown-$(date +%s)-$$"
mkdir -p "$DIR" && : > "$DIR/$ID"
exit 0
