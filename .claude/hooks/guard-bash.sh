#!/usr/bin/env bash
# PreToolUse(Bash) フック: 取り返しのつかないコマンドを止める（安全な作業環境 = ルール定義の 4 要件の 2）
# 入力: stdin JSON {"tool_name":"Bash","tool_input":{"command":"..."},"cwd":"..."}
# 出力: 拒否なら permissionDecision=deny、確認させたいなら ask。何も出さなければ通常の許可フローへ
#
# ヒアドキュメントや引用符の中の文字も判定する（外す仕組みは抜け道が続いたのでやめた。issue #23）。
# issue・PR・コミットの本文は Write でファイルに書き、--body-file / -F で渡す（rules/common/git-workflow.md）
set -u
INPUT="$(cat)"
CMD="$(printf '%s' "$INPUT" | jq -r '.tool_input.command // ""')"
BASE="$(printf '%s' "$INPUT" | jq -r '.cwd // ""')"
[ -n "$BASE" ] || BASE="${CLAUDE_PROJECT_DIR:-$PWD}"

BODY_HINT="issue・PR・コミットの本文にこの文字があるなら、本文を Write でファイルに書き、--body-file / -F で渡してください。"

deny() {
  jq -n --arg r "$1" '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"deny",permissionDecisionReason:$r}}'
  exit 0
}
ask() {
  jq -n --arg r "$1" '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"ask",permissionDecisionReason:$r}}'
  exit 0
}

# 行末の \ と改行（行の継続）を空白 1 つにする。続きの行に --force を送る抜け道をふさぐ
CMD="${CMD//\\$'\n'/ }"
# git の判定用に表記をそろえた形: 引用符と \ を消し、リダイレクトの < > を空白にする（消すのは記号だけで、判定から外す文字はない）。
# `git push "--force"`・`bash -c 'git push origin main'`・`--force>/dev/null` を、ふつうの形と同じに判定するため
FLAT="$(printf '%s' "$CMD" | tr -d "\"'\\\\" | tr '<>' '  ')"

# git とサブコマンドの間に入る全体のオプション（-C <場所>、-c <設定>、--git-dir <場所>、--no-pager など）も含めて git の呼び出しを捉える
GIT_OPTS='([[:space:]]+(-C|-c|--git-dir|--work-tree|--namespace|--exec-path|--config-env|--super-prefix)[[:space:]]+[^[:space:]]+|[[:space:]]+--?[[:alnum:]][[:alnum:]-]*(=[^[:space:]]+)?)*'
G="(^|[^[:alnum:]_.-])git${GIT_OPTS}[[:space:]]+"

# rm -rf の危険対象（ルート、ホーム、プロジェクト直下、api / web 丸ごと、相対の .）
if printf '%s' "$CMD" | grep -Eq 'rm\s+(-[a-zA-Z]*r[a-zA-Z]*f|-[a-zA-Z]*f[a-zA-Z]*r)\s+("?/"?|~|\$HOME|\.\s*$|\.\s|\*|(\./)?(api|web)/?(\s|$)|"?\$CLAUDE_PROJECT_DIR"?/?\s*$)'; then
  deny "rm -rf の対象が広すぎます（/, ~, プロジェクト直下, api/, web/）。対象ディレクトリを限定してください。"
fi
# 長いオプションは略せる（--ha = --hard、--f = --force）ので前方一致で見る
printf '%s' "$FLAT" | grep -Eq "${G}reset([[:space:]].*)?[[:space:]]--ha" && deny "git reset --hard は作業を失います。git stash か git checkout <file> を使ってください。${BODY_HINT}"
printf '%s' "$FLAT" | grep -Eq "${G}clean([[:space:]].*)?[[:space:]](-[a-zA-Z]*f|--f)" && deny "git clean -f は未追跡ファイルを消します。禁止です。${BODY_HINT}"
printf '%s' "$FLAT" | grep -Eq "${G}checkout[[:space:]]+--[[:space:]]+\." && deny "git checkout -- . は全変更を破棄します。ファイルを限定してください。"
printf '%s' "$CMD" | grep -Eiq 'drop\s+(table|database)' && deny "DROP TABLE/DATABASE は禁止です。make fresh（migrate:fresh --seed）を使ってください。"
printf '%s' "$CMD" | grep -Eq 'artisan\s+db:wipe' && deny "db:wipe は禁止です。make fresh を使ってください。"
printf '%s' "$CMD" | grep -Eq 'docker\s+compose\s+down\s+.*(-v\b|--volumes)' && deny "docker compose down -v は Redis のデータ（キュー・失敗ジョブの観察対象）を消します。make down を使ってください。"
printf '%s' "$CMD" | grep -Eiq 'redis-cli\s+.*flush(all|db)' && ask "Redis を全消去します。キューに積まれたジョブと Horizon の記録が消えますが、よいですか？"
printf '%s' "$CMD" | grep -Eq 'artisan\s+(queue:flush|horizon:clear|queue:clear)' && ask "失敗ジョブ／キューを消します。観察・再実行の対象が消えますが、よいですか？"
printf '%s' "$CMD" | grep -Eq 'npm\s+(install|i|uninstall|remove)\s+[^-]|composer\s+(require|remove)\s' && ask "依存パッケージを変更します。docs/01_技術仕様_next.md の「使うパッケージ」にあるものですか？"

# --- git push の判定 ---
# コマンドを && || ; | & ( ) ` 改行で区切り、区切りごとに判定する。
# 全体で判定すると `git push origin issue/25 && gh pr create --base main` の main を push 先と取り違える（issue #23）
# --force と main への直接 push は止める（マージは人が PR で行う）。issue/<番号> への push は確認しない:
# 合否は /pr-create の make verify と CI（.github/workflows/ci.yml）が判定する（issue #8。毎回の確認は自動の作業を止めるだけだった）
resolve() { # $1 = 場所、$2 = 基準の場所
  local p="$1"
  case "$p" in "~") p="$HOME" ;; "~/"*) p="$HOME/${p#\~/}" ;; /*) ;; *) p="$2/$p" ;; esac
  printf '%s' "$p"
}

PUSH_RE="(^|[^[:alnum:]_.-])(git${GIT_OPTS})[[:space:]]+push([[:space:]]+(.*))?$"
dir="$BASE"   # 空 = cd の行き先を決められなかった
while IFS= read -r seg; do
  # cd / pushd <場所> の後の git は、その場所で動く。変数（$VAR）や cd - は行き先を決められないので「不明」にする
  if [[ $seg =~ ^[[:space:]]*(cd|pushd)[[:space:]]+([^[:space:]]+)[[:space:]]*$ ]]; then
    arg="${BASH_REMATCH[2]}"
    case "$arg" in *'$'*|-) dir=""; continue ;; esac
    if [ -n "$dir" ] || [[ $arg == /* || $arg == "~"* ]]; then
      next="$(resolve "$arg" "$dir")"
      [ -d "$next" ] && dir="$next"
    fi
    continue
  fi
  [[ $seg =~ $PUSH_RE ]] || continue
  prefix="${BASH_REMATCH[2]}"
  rest=" ${BASH_REMATCH[7]} "  # 1 境目 / 2 git と全体のオプション / 3〜5 GIT_OPTS の中 / 6〜7 push の後ろ

  # 強制: --force 系（git は長いオプションを略せるので --for… の前方一致）、--mirror（--mi…）、-f を含む短いオプション、+<refspec>
  if [[ $rest =~ [[:space:]](--for[[:alnum:]-]*|--mi[[:alnum:]-]*|-[[:alnum:]]*f[[:alnum:]]*)([[:space:]=]) ]] \
    || [[ $rest =~ [[:space:]]\+[^[:space:]] ]]; then
    deny "git push --force（--mirror・+<refspec> を含む）は禁止です（rules/common/git-workflow.md）。${BODY_HINT}"
  fi
  if [[ $rest =~ [[:space:]]([^[:space:]]*:)?(refs/heads/)?main[[:space:]] ]]; then
    deny "main への直接 push は禁止です。issue/<番号> ブランチを push して /pr-create で PR を作ってください。${BODY_HINT}"
  fi
  # -c remote.origin.push=+HEAD:refs/heads/main のように、push 先や強制を設定で渡す形も止める
  if [[ $prefix =~ (^|[[:space:]])(-c|--config-env)[[:space:]]+[^[:space:]]*([Pp][Uu][Ss][Hh]|[Mm][Ii][Rr][Rr][Oo][Rr]) ]]; then
    deny "git -c で push の設定（push・mirror）を変えて push しないでください。${BODY_HINT}"
  fi

  # push 元のブランチ: -C があればその場所、なければ今の場所（cd の後なら cd 先）。--git-dir があればそれも渡す
  where="$dir"
  if [[ $prefix =~ (^|[[:space:]])-C[[:space:]]+([^[:space:]]+) ]]; then
    if [ -n "$dir" ] || [[ ${BASH_REMATCH[2]} == /* || ${BASH_REMATCH[2]} == "~"* ]]; then
      where="$(resolve "${BASH_REMATCH[2]}" "$dir")"
    fi
  fi
  if [ -z "$where" ]; then
    deny "cd の行き先を決められないので、push 元のブランチを判定できません。場所を絶対パスで書くか、git -C <場所> push にしてください。"
  fi
  gitdir=()
  if [[ $prefix =~ (^|[[:space:]])--git-dir[[:space:]=]+([^[:space:]]+) ]]; then
    gitdir=(--git-dir "$(resolve "${BASH_REMATCH[2]}" "$where")")
  fi
  branch="$(git -C "$where" ${gitdir[@]+"${gitdir[@]}"} branch --show-current 2>/dev/null)"
  if [ -z "$branch" ]; then
    deny "push 元のブランチを判定できません（場所: ${where}）。issue/<番号> ブランチの worktree で、場所を確かめてから push してください。${BODY_HINT}"
  fi
  if [ "$branch" = "main" ]; then
    deny "main ブランチの上では push しません（場所: ${where}）。issue/<番号> ブランチで作業してください。${BODY_HINT}"
  fi
done < <(printf '%s\n' "$FLAT" | awk '{ gsub(/&&|\|\||;|\||&|\(|\)|`/, "\n"); print }')

exit 0
