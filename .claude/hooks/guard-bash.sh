#!/usr/bin/env bash
# PreToolUse(Bash) フック: 取り返しのつかないコマンドを止める（安全な作業環境 = ルール定義の 4 要件の 2）
# 入力: stdin JSON {"tool_name":"Bash","tool_input":{"command":"..."},"cwd":"..."}
# 出力: 拒否なら permissionDecision=deny、確認させたいなら ask。何も出さなければ通常の許可フローへ
#
# 目的は「うっかりの防止」（人が決定、issue #23）。ふつうに打ちうる書き方（引用符・リダイレクト・行の継続・
# git -C / -c・cd の後の git・略した長いオプション）は止める。わざと迂回する書き方（変数やブレースの展開、
# alias、-C を重ねるなど）は文字の判定では止めきれないので、GitHub の main のブランチ保護
# （PR 必須・管理者にも適用・強制 push と削除の禁止）に任せる。形を読めない git push は止める側に倒す。
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

# rm -rf の危険対象（ルート、ホーム、プロジェクト直下、api / web 丸ごと、相対の .）
if printf '%s' "$CMD" | grep -Eq 'rm\s+(-[a-zA-Z]*r[a-zA-Z]*f|-[a-zA-Z]*f[a-zA-Z]*r)\s+("?/"?|~|\$HOME|\.\s*$|\.\s|\*|(\./)?(api|web)/?(\s|$)|"?\$CLAUDE_PROJECT_DIR"?/?\s*$)'; then
  deny "rm -rf の対象が広すぎます（/, ~, プロジェクト直下, api/, web/）。対象ディレクトリを限定してください。"
fi
printf '%s' "$CMD" | grep -Eiq 'drop\s+(table|database)' && deny "DROP TABLE/DATABASE は禁止です。make fresh（migrate:fresh --seed）を使ってください。"
printf '%s' "$CMD" | grep -Eq 'artisan\s+db:wipe' && deny "db:wipe は禁止です。make fresh を使ってください。"
printf '%s' "$CMD" | grep -Eq 'docker\s+compose\s+down\s+.*(-v\b|--volumes)' && deny "docker compose down -v は Redis のデータ（キュー・失敗ジョブの観察対象）を消します。make down を使ってください。"
printf '%s' "$CMD" | grep -Eiq 'redis-cli\s+.*flush(all|db)' && ask "Redis を全消去します。キューに積まれたジョブと Horizon の記録が消えますが、よいですか？"
printf '%s' "$CMD" | grep -Eq 'artisan\s+(queue:flush|horizon:clear|queue:clear)' && ask "失敗ジョブ／キューを消します。観察・再実行の対象が消えますが、よいですか？"
printf '%s' "$CMD" | grep -Eq 'npm\s+(install|i|uninstall|remove)\s+[^-]|composer\s+(require|remove)\s' && ask "依存パッケージを変更します。docs/01_技術仕様_next.md の「使うパッケージ」にあるものですか？"

# ===== git の判定 =====
# 行末の \ と改行（行の継続）を空白 1 つにする。続きの行に --force を送る形をふさぐ
CMD="${CMD//\\$'\n'/ }"
# 判定用に表記をそろえた形を 2 つ作り、どちらかで止まれば止める（消すのは記号だけで、判定から外す文字はない）
# - FLAT : 引用符と \ を消し、< > を空白にする。引用符の中の空白は残る（bash -c 'git push --force' の中の git を読むため）
# - FLATQ: 引用符の中の空白を \001 にしてから引用符を消す（-c "user.name=A B" や -C "/a b" を 1 つの値として読むため。
#          FLAT ではこの値が割れてサブコマンドを取り違えるので、push かどうかは FLATQ の判定で決まる）
FLAT="$(printf '%s' "$CMD" | tr -d "\"'\\\\" | tr '<>' '  ')"
FLATQ="$(printf '%s\n' "$CMD" | awk '
  { out = ""
    for (i = 1; i <= length($0); i++) {
      c = substr($0, i, 1)
      if (q == "" && (c == "\"" || c == "\047")) { q = c; continue }
      if (q != "" && c == q) { q = ""; continue }
      if (c == "\\") continue
      if (q != "" && (c == " " || c == "\t")) { out = out "\001"; continue }
      if (q == "" && (c == "<" || c == ">")) { out = out " "; continue }
      out = out c
    }
    print out }')"

# git とサブコマンドの間に入る全体のオプション（-C <場所>、-c <設定>、--git-dir <場所>、--no-pager など）も含めて git の呼び出しを捉える
GIT_OPTS='([[:space:]]+(-C|-c|--git-dir|--work-tree|--namespace|--exec-path|--config-env|--super-prefix)[[:space:]]+[^[:space:]]+|[[:space:]]+--?[[:alnum:]][[:alnum:]-]*(=[^[:space:]]+)?)*'
G="(^|[^[:alnum:]_.-])git${GIT_OPTS}[[:space:]]+"
PUSH_RE="(^|[^[:alnum:]_.-])(git${GIT_OPTS})[[:space:]]+push([[:space:]]+(.*))?$"
# 区切りの頭で git をコマンドとして呼んでいる形（VAR=値 や env / command / sudo などが前に付いてもよい）
GIT_CMD_RE='^[[:space:]]*(([A-Za-z_][A-Za-z0-9_]*=[^[:space:]]*|env|command|builtin|sudo|exec|nohup|time)[[:space:]]+)*([^[:space:]]*/)?git([[:space:]]|$)'
CD_RE='^[[:space:]]*((builtin|command)[[:space:]]+)?(cd|pushd|popd)([[:space:]]|$)'
CD_SIMPLE_RE='^[[:space:]]*((builtin|command)[[:space:]]+)?(cd|pushd)[[:space:]]+([^[:space:]-][^[:space:]]*)[[:space:]]*$'

resolve() { # $1 = 場所（\001 は空白に戻す）、$2 = 基準の場所
  local p; p="$(printf '%s' "$1" | tr '\001' ' ')"
  case "$p" in "~") p="$HOME" ;; "~/"*) p="$HOME/${p#\~/}" ;; /*) ;; *) p="$2/$p" ;; esac
  printf '%s' "$p"
}

# コマンドを && || ; | & ( ) ` 改行で区切り、区切りごとに判定する。
# 全体で判定すると `git push origin issue/25 && gh pr create --base main` の main を push 先と取り違える（issue #23）
# --force と main への直接 push は止める（マージは人が PR で行う）。issue/<番号> への push は確認しない:
# 合否は /pr-create の make verify と CI（.github/workflows/ci.yml）が判定する（issue #8。毎回の確認は自動の作業を止めるだけだった）
split() { printf '%s\n' "$1" | awk '{ gsub(/&&|\|\||;|\||&|\(|\)|`/, "\n"); print }'; }

check() { # $1 = 表記をそろえたコマンド（FLAT か FLATQ）
  local dir="$BASE" seg arg next prefix rest where branch
  local -a gitdir
  while IFS= read -r seg; do
    # cd / pushd <場所> の後の git は、その場所で動く。行き先を決められない形（cd -P、cd … 2>/dev/null、cd "$VAR"、cd -、popd）は「不明」にする
    if [[ $seg =~ $CD_RE ]]; then
      if [[ $seg =~ $CD_SIMPLE_RE ]] && [[ ${BASH_REMATCH[4]} != *'$'* ]] && [ -n "$dir" -o "${BASH_REMATCH[4]:0:1}" = "/" -o "${BASH_REMATCH[4]:0:1}" = "~" ]; then
        next="$(resolve "${BASH_REMATCH[4]}" "$dir")"
        [ -d "$next" ] && dir="$next"
      else
        dir=""
      fi
      continue
    fi

    # 作業を失う操作。長いオプションは略せる（--ha = --hard、--f = --force）ので前方一致で見る
    [[ $seg =~ ${G}reset([[:space:]].*)?[[:space:]]--ha ]] && deny "git reset --hard は作業を失います。git stash か git checkout <file> を使ってください。${BODY_HINT}"
    [[ $seg =~ ${G}clean([[:space:]].*)?[[:space:]](-[a-zA-Z]*f|--f) ]] && deny "git clean -f は未追跡ファイルを消します。禁止です。${BODY_HINT}"
    [[ $seg =~ ${G}checkout([[:space:]].*)?[[:space:]](\./?|:/)([[:space:]]|$) ]] && deny "git checkout . は全変更を破棄します。ファイルを限定してください。${BODY_HINT}"
    # restore --staged（-S）だけならステージを外すだけで作業ツリーは失われないので通す（--worktree / -W があれば止める）
    if [[ $seg =~ ${G}restore([[:space:]].*)?[[:space:]](\./?|:/)([[:space:]]|$) ]] \
      && ! { [[ " $seg " =~ [[:space:]](--staged|-S)[[:space:]] ]] && ! [[ " $seg " =~ [[:space:]](--worktree|-W)[[:space:]] ]]; }; then
      deny "git restore . は全変更を破棄します。ファイルを限定してください。${BODY_HINT}"
    fi
    [[ $seg =~ ${G}(checkout|switch)([[:space:]].*)?[[:space:]](-[a-zA-Z]*f|--f|--disc) ]] && deny "git checkout -f / git switch -f は未コミットの変更を捨てます。git stash を使ってください。${BODY_HINT}"

    if ! [[ $seg =~ $PUSH_RE ]]; then
      # git をコマンドとして呼び、push という語があるのに、サブコマンドを読めない → 止める側に倒す。
      # サブコマンドが push 以外と読めたら（git stash push、git help push、git log --grep push など）通す。
      # 値が割れて push 以外に見える形（FLAT の git -c a=b c push）は、FLATQ の check で push として判定される
      if [[ $seg =~ $GIT_CMD_RE ]] && [[ " $seg " =~ [[:space:]]push[[:space:]] ]]; then
        if [[ $seg =~ ${G}([^[:space:]]+) ]] && [ "${BASH_REMATCH[$(( ${#BASH_REMATCH[@]} - 1 ))]}" != "push" ]; then
          continue
        fi
        deny "git push の形を読めないので止めました。git [-C <場所>] push <リモート> <ブランチ> の形で、値は直接書いてください。${BODY_HINT}"
      fi
      continue
    fi
    prefix="${BASH_REMATCH[2]}"
    rest=" ${BASH_REMATCH[7]} "  # 1 境目 / 2 git と全体のオプション / 3〜5 GIT_OPTS の中 / 6〜7 push の後ろ

    # 強制: --force 系（--for… の前方一致）、--mirror（--m…）、-f を含む短いオプション、+<refspec>
    if [[ $rest =~ [[:space:]](--for[[:alnum:]-]*|--m[[:alnum:]-]*|-[[:alnum:]]*f[[:alnum:]]*)([[:space:]=]) ]] \
      || [[ $rest =~ [[:space:]]\+[^[:space:]] ]]; then
      deny "git push --force（--mirror・+<refspec> を含む）は禁止です（rules/common/git-workflow.md）。${BODY_HINT}"
    fi
    # 全部のブランチ（手元の main も入る）
    if [[ $rest =~ [[:space:]](--al[[:alnum:]-]*|--bra[[:alnum:]-]*)([[:space:]=]) ]]; then
      deny "git push --all / --branches は手元の main も push します。issue/<番号> ブランチだけを push してください。"
    fi
    # main への push（main、HEAD:main、refs/heads/main、heads/main、:main）
    if [[ $rest =~ [[:space:]]([^[:space:]]*:)?(refs/)?(heads/)?main[[:space:]] ]]; then
      deny "main への直接 push は禁止です。issue/<番号> ブランチを push して /pr-create で PR を作ってください。${BODY_HINT}"
    fi
    # push 先や強制を設定で渡す形（-c remote.origin.push=…、--config-env=…）
    if [[ $prefix =~ (^|[[:space:]])(-c|--config-env)[[:space:]=]+[^[:space:]]*([Pp][Uu][Ss][Hh]|[Mm][Ii][Rr][Rr][Oo][Rr]) ]]; then
      deny "git -c で push の設定（push・mirror）を変えて push しないでください。${BODY_HINT}"
    fi
    # 場所を環境変数で渡す形は、push 元を判定できない
    if [[ $1 =~ (^|[^[:alnum:]_])GIT_(DIR|WORK_TREE|COMMON_DIR)= ]]; then
      deny "GIT_DIR などの環境変数を付けた push は、push 元のブランチを判定できません。git -C <場所> push にしてください。"
    fi
    if [[ $prefix =~ -C[[:space:]].*[[:space:]]-C[[:space:]] ]]; then
      deny "git -C を重ねた push は、push 元のブランチを判定できません。-C は 1 つにしてください。"
    fi

    # push 元のブランチ: -C があればその場所、なければ今の場所（cd の後なら cd 先）。--git-dir があればそれも渡す
    where="$dir"
    if [[ $prefix =~ (^|[[:space:]])-C[[:space:]]+([^[:space:]]+) ]]; then
      if [ -n "$dir" ] || [[ ${BASH_REMATCH[2]} == /* || ${BASH_REMATCH[2]} == "~"* ]]; then
        where="$(resolve "${BASH_REMATCH[2]}" "$dir")"
      fi
    fi
    if [ -z "$where" ]; then
      deny "cd の行き先を決められないので、push 元のブランチを判定できません。git -C <絶対パス> push にしてください。"
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
  done < <(split "$1")
}

check "$FLAT"
check "$FLATQ"

exit 0
