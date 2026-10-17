#!/usr/bin/env bash
# PreToolUse(Edit|Write|MultiEdit) フック: 秘密情報・生成物・確定済み基盤を編集させない
# 入力: stdin JSON {"tool_input":{"file_path":"..."}}
# 出力: 拒否なら permissionDecision=deny、人に確かめさせるなら ask
set -u
ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
INPUT="$(cat)"
FILE="$(printf '%s' "$INPUT" | jq -r '.tool_input.file_path // ""')"
[ -z "$FILE" ] && exit 0

# プロジェクトルート（next_team/）からの相対パスに正規化
REL="${FILE#"$ROOT"/}"

deny() {
  jq -n --arg r "$1" '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"deny",permissionDecisionReason:$r}}'
  exit 0
}
ask() {
  jq -n --arg r "$1" '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"ask",permissionDecisionReason:$r}}'
  exit 0
}

case "$REL" in
  api/.env.example|web/.env.example) ;;
  api/.env|api/.env.*|web/.env|web/.env.*)
    deny ".env は編集禁止です（秘密情報）。.env.example を編集し、人が .env に反映します。" ;;
  api/composer.lock) deny "composer.lock は composer コマンド経由でのみ変わります。直接編集しないでください。" ;;
  web/package-lock.json) deny "package-lock.json は npm コマンド経由でのみ変わります。直接編集しないでください。" ;;
  api/vendor/*) deny "api/vendor/ は編集禁止です。" ;;
  web/node_modules/*) deny "web/node_modules/ は編集禁止です。" ;;
  web/.next/*) deny "web/.next/ はビルド生成物です。編集しないでください。" ;;
  web/next-env.d.ts) deny "next-env.d.ts は Next.js が生成します。編集しないでください。" ;;
  api/storage/*) deny "api/storage/ は生成物（ログ・CSV 出力・キャッシュ）です。編集しないでください。" ;;
  api/database/database.sqlite*) deny "SQLite ファイルを直接編集しないでください。" ;;
  # 検証に差し戻されている最中にフックを緩める、を人の目で止めるため、毎回承認を挟む（issue #8）
  .claude/hooks/*|.claude/settings.json)
    ask "フックか settings.json を変更します。検証や守りを緩める変更でないか確かめて承認してください。" ;;
  api/database/migrations/*)
    if [ -f "$ROOT/.claude/.migrations-locked" ]; then
      deny "マイグレーションは基盤確定後ロックされています（.claude/.migrations-locked）。新しい列やテーブルは新しいマイグレーションファイルを足す形にし、既存ファイルの変更が必要なら人に相談してください。"
    fi ;;
esac
exit 0
