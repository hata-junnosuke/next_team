#!/usr/bin/env bash
# PostToolUse(Edit|Write|MultiEdit) フック: 編集したファイルを整形する（整形の指摘で検証ループを消費しない）
#   api/**/*.php                       → Pint
#   web/**/*.{ts,tsx,css,json,mjs}     → Prettier
# ついでに web/ を編集した印（.claude/.dirty-web）を付ける。Stop フックが web の検査をするかの判断に使う。
# mobile/ を編集したら印（.claude/.dirty-mobile）だけを付ける（整形はしない。Step 9 の issue #50）。
set -u
ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
INPUT="$(cat)"
FILE="$(printf '%s' "$INPUT" | jq -r '.tool_input.file_path // ""')"
[ -f "$FILE" ] || exit 0
REL="${FILE#"$ROOT"/}"

case "$REL" in
  api/vendor/*|api/storage/*|api/bootstrap/cache/*) exit 0 ;;
  api/*.php)
    [ -x "$ROOT/api/vendor/bin/pint" ] || exit 0
    (cd "$ROOT/api" && vendor/bin/pint --quiet "$FILE" >/dev/null 2>&1) || true ;;
  web/node_modules/*|web/.next/*) exit 0 ;;
  web/*)
    : > "$ROOT/.claude/.dirty-web"
    case "$REL" in
      *.ts|*.tsx|*.css|*.json|*.mjs)
        [ -x "$ROOT/web/node_modules/.bin/prettier" ] || exit 0
        (cd "$ROOT/web" && node_modules/.bin/prettier --write --log-level silent "$FILE" >/dev/null 2>&1) || true ;;
    esac ;;
  mobile/build/*|mobile/.dart_tool/*) exit 0 ;;
  mobile/*)
    : > "$ROOT/.claude/.dirty-mobile" ;;
esac
exit 0
