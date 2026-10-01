#!/usr/bin/env bash
# PreToolUse(Bash) フック: 取り返しのつかないコマンドを止める（安全な作業環境 = ルール定義の 4 要件の 2）
# 入力: stdin JSON {"tool_name":"Bash","tool_input":{"command":"..."}}
# 出力: 拒否なら permissionDecision=deny、確認させたいなら ask。何も出さなければ通常の許可フローへ
set -u
INPUT="$(cat)"
CMD="$(printf '%s' "$INPUT" | jq -r '.tool_input.command // ""')"

deny() {
  jq -n --arg r "$1" '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"deny",permissionDecisionReason:$r}}'
  exit 0
}
ask() {
  jq -n --arg r "$1" '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"ask",permissionDecisionReason:$r}}'
  exit 0
}

# rm -rf の危険対象（ルート、ホーム、プロジェクト直下、api / web 丸ごと、相対の .）
if printf '%s' "$CMD" | grep -Eq 'rm\s+(-[a-zA-Z]*r[a-zA-Z]*f|-[a-zA-Z]*f[a-zA-Z]*r)\s+("?/"?|~|\$HOME|\.\s*$|\.\s|\*|(\./)?(api|web)/?(\s|$)|"?\$CLAUDE_PROJECT_DIR"?/?\s*$)'; then
  deny "rm -rf の対象が広すぎます（/, ~, プロジェクト直下, api/, web/）。対象ディレクトリを限定してください。"
fi
printf '%s' "$CMD" | grep -Eq 'git\s+push\s+.*(--force|-f\b|--force-with-lease)' && deny "git push --force は禁止です（rules/common/git-workflow.md）。"
printf '%s' "$CMD" | grep -Eq 'git\s+reset\s+--hard' && deny "git reset --hard は作業を失います。git stash か git checkout <file> を使ってください。"
printf '%s' "$CMD" | grep -Eq 'git\s+clean\s+-[a-zA-Z]*f' && deny "git clean -f は未追跡ファイルを消します。禁止です。"
printf '%s' "$CMD" | grep -Eq 'git\s+checkout\s+--\s+\.' && deny "git checkout -- . は全変更を破棄します。ファイルを限定してください。"
printf '%s' "$CMD" | grep -Eiq 'drop\s+(table|database)' && deny "DROP TABLE/DATABASE は禁止です。make fresh（migrate:fresh --seed）を使ってください。"
printf '%s' "$CMD" | grep -Eq 'artisan\s+db:wipe' && deny "db:wipe は禁止です。make fresh を使ってください。"
printf '%s' "$CMD" | grep -Eq 'docker\s+compose\s+down\s+.*(-v\b|--volumes)' && deny "docker compose down -v は Redis のデータ（キュー・失敗ジョブの観察対象）を消します。make down を使ってください。"
printf '%s' "$CMD" | grep -Eiq 'redis-cli\s+.*flush(all|db)' && ask "Redis を全消去します。キューに積まれたジョブと Horizon の記録が消えますが、よいですか？"
printf '%s' "$CMD" | grep -Eq 'artisan\s+(queue:flush|horizon:clear|queue:clear)' && ask "失敗ジョブ／キューを消します。観察・再実行の対象が消えますが、よいですか？"
printf '%s' "$CMD" | grep -Eq 'npm\s+(install|i|uninstall|remove)\s+[^-]|composer\s+(require|remove)\s' && ask "依存パッケージを変更します。docs/01_技術仕様_next.md の「使うパッケージ」にあるものですか？"
printf '%s' "$CMD" | grep -Eq 'git\s+push(\s|$)' && ask "git push の前に /verify pre-commit を通しましたか？"

exit 0
