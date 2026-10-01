#!/usr/bin/env bash
# PostToolUse(Edit|Write|MultiEdit) フック: デバッグ出力が残っていたら差し戻す
#   api: dd() / dump() / var_dump() / ray()
#   web: console.log()（console.error / console.warn は許可）
# 出力: {"decision":"block","reason":"..."} で Claude に修正を促す
set -u
ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
INPUT="$(cat)"
FILE="$(printf '%s' "$INPUT" | jq -r '.tool_input.file_path // ""')"
[ -f "$FILE" ] || exit 0
REL="${FILE#"$ROOT"/}"

case "$REL" in
  api/app/*.php|api/routes/*.php|api/database/*.php|api/config/*.php|api/resources/*.php|api/bootstrap/app.php)
    PATTERN='(^|[^A-Za-z0-9_>:])(dd|dump|var_dump|ray)\(' ;;
  web/node_modules/*|web/.next/*) exit 0 ;;
  web/app/*.ts|web/app/*.tsx|web/lib/*.ts|web/lib/*.tsx|web/components/*.ts|web/components/*.tsx|web/proxy.ts)
    PATTERN='console\.log\(' ;;
  *) exit 0 ;;   # tests/ や docs/ は対象外
esac

HITS="$(grep -nE "$PATTERN" "$FILE" | grep -vE '^[0-9]+:\s*(//|\*|#)' || true)"
if [ -n "$HITS" ]; then
  jq -n --arg r "デバッグ出力が残っています。削除してください:
${REL}
${HITS}" '{decision:"block", reason:$r}'
fi
exit 0
