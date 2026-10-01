#!/usr/bin/env bash
# SubagentStop フック: 担当（implementer-api / implementer-web 等）が終わるとき、変更したファイルだけを軽く検査する。
#   api: 変更した PHP の構文（php -l）と Pint
#   web: 変更した TS/TSX の ESLint
# 全テスト・全体の型チェックは回さない（並列実装中は他担当の分が赤で正常のため）。全体の緑はリードの Stop フックが保証する。
# 上限 2 回（.claude/.subagent-loop-count）
set -u

format_pint() {
  if printf '%s' "$1" | grep -q '"tool":"pint"'; then
    printf '%s' "$1" | jq -r '.files[]? | "  " + .path + "  (" + (.fixers | join(", ")) + ")"' 2>/dev/null | head -20
  else
    printf '%s' "$1" | grep -E '⨯|\.php' | head -20
  fi
}
ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
MAX=2
INPUT="$(cat)"
ACTIVE="$(printf '%s' "$INPUT" | jq -r '.stop_hook_active // false')"
AGENT_ID="$(printf '%s' "$INPUT" | jq -r '.agent_id // empty' 2>/dev/null | tr -cd 'A-Za-z0-9_-')"
# 差し戻し回数は担当ごとに数える（並列の担当が互いの回数を 0 に戻さないように）
COUNTER="$ROOT/.claude/.subagent-loop-count${AGENT_ID:+.$AGENT_ID}"

# 稼働中の印（subagent-start.sh が置く）は、まず消す。差し戻す（block）ときだけ置き直す。
#   「消すのが既定、残すのが例外」にしておくと、このフックが timeout で殺されても印が残らない
#   （印が残ると、リードの Stop フックが検査を飛ばし続ける）
RUNNING_DIR="$ROOT/.claude/.running"
MARK=""
if [ -n "$AGENT_ID" ]; then
  MARK="$RUNNING_DIR/$AGENT_ID"
else
  # agent_id が取れないときに限り、id なしで置かれた印（unknown-*）のいちばん古いものを対応させる
  OLDEST="$(ls -tr "$RUNNING_DIR" 2>/dev/null | grep '^unknown-' | head -1)"
  [ -n "$OLDEST" ] && MARK="$RUNNING_DIR/$OLDEST"
fi
[ -n "$MARK" ] && rm -f "$MARK"

cd "$ROOT" || exit 0
git rev-parse --git-dir >/dev/null 2>&1 || exit 0

[ "$ACTIVE" = "true" ] || echo 0 > "$COUNTER"
COUNT="$(cat "$COUNTER" 2>/dev/null || echo 0)"
if [ "$COUNT" -ge "$MAX" ]; then
  rm -f "$COUNTER"; exit 0
fi

# 変更ファイル（コミット前の変更 + 未追跡）。初回コミット前は HEAD がないので未追跡だけになる
CHANGED="$( { git diff --name-only HEAD 2>/dev/null; git ls-files --others --exclude-standard; } | sort -u)"
PHP_FILES="$(printf '%s\n' "$CHANGED" | grep -E '^api/.*\.php$' | grep -vE '^api/(vendor|storage|bootstrap/cache)/' || true)"
TS_FILES="$(printf '%s\n' "$CHANGED" | grep -E '^web/.*\.(ts|tsx)$' | grep -vE '^web/(node_modules|\.next)/|next-env\.d\.ts$' || true)"
[ -z "$PHP_FILES" ] && [ -z "$TS_FILES" ] && { rm -f "$COUNTER"; exit 0; }

FAIL=""
if [ -n "$PHP_FILES" ] && [ -x api/vendor/bin/pint ]; then
  REL_PHP=""
  for f in $PHP_FILES; do
    [ -f "$f" ] || continue
    OUT="$(php -l "$f" 2>&1)" || FAIL="${FAIL}構文エラー: ${OUT}
"
    REL_PHP="$REL_PHP ${f#api/}"
  done
  if [ -n "$REL_PHP" ]; then
    # shellcheck disable=SC2086
    PINT_OUT="$(cd api && vendor/bin/pint --test $REL_PHP 2>&1)" || FAIL="${FAIL}Pint 未整形（make api-pint）:
$(format_pint "$PINT_OUT")
"
  fi
fi
if [ -n "$TS_FILES" ] && [ -x web/node_modules/.bin/eslint ]; then
  REL_TS=""
  for f in $TS_FILES; do [ -f "$f" ] && REL_TS="$REL_TS ${f#web/}"; done
  if [ -n "$REL_TS" ]; then
    # shellcheck disable=SC2086
    LINT_OUT="$(cd web && node_modules/.bin/eslint $REL_TS 2>&1)" || FAIL="${FAIL}ESLint 失敗:
$(printf '%s' "$LINT_OUT" | grep -vE '^\s*$' | head -30)
"
  fi
fi

if [ -n "$FAIL" ]; then
  COUNT=$((COUNT + 1)); echo "$COUNT" > "$COUNTER"
  # 担当は直すために動き続けるので、印を置き直す
  [ -n "$MARK" ] && mkdir -p "$RUNNING_DIR" && : > "$MARK"
  jq -n --arg r "担当の終了前チェック ${COUNT}/${MAX}: 以下を直してから終了してください。
${FAIL}" '{decision:"block", reason:$r}'
  exit 0
fi
rm -f "$COUNTER"
exit 0
