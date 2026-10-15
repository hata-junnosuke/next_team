#!/usr/bin/env bash
# SessionStart フック: プロジェクトの現在地を Claude に伝える（open の issue と直近のコミットを読み戻す入口）
# 入力: stdin JSON {"source":"startup|resume|compact", ...}
# 出力: stdout の文章がそのままセッションのコンテキストに追加される
set -u
ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
cd "$ROOT" || exit 0

# 新しいセッションでは検証ループの回数を 0 に戻す（前セッションの途中状態を引きずらない）
rm -f .claude/.loop-count .claude/.subagent-loop-count .claude/.subagent-loop-count.*
# 担当が動いている印も消す（前セッションの異常終了で残ると、Stop フックの検査が飛ばされ続ける）。
# ただし圧縮（compact）のときは消さない。担当がまだ動いている最中に圧縮されることがあるため
SOURCE="$(jq -r '.source // "startup"' 2>/dev/null || echo startup)"
[ "$SOURCE" = "compact" ] || rm -rf .claude/.running

TEST_COUNT="$(grep -rhoE 'function test_[^ (]+' api/tests 2>/dev/null | wc -l | tr -d ' ')"
DIRTY="$(git status --short 2>/dev/null | wc -l | tr -d ' ')"

echo "## next_team セッション開始情報"
echo "- 起動場所: ${ROOT}（リポジトリの直下なら Stop フックが効きます。worktree の直下でもよい）"
# worktree（~/orca/workspaces/next_team/<名前> など）でも効くよう、名前ではなく git の最上位と比べる
TOPLEVEL="$(git rev-parse --show-toplevel 2>/dev/null)"
if [ "$TOPLEVEL" != "$ROOT" ] || [ ! -d api ] || [ ! -d web ]; then
  echo "- **警告**: 起動場所がリポジトリの直下（api/ と web/ がある場所）ではありません。フックの相対パスがずれます。直下に cd して claude で入り直してください"
fi
echo "- api のテスト関数: ${TEST_COUNT} 件 / 未コミット変更: ${DIRTY} ファイル"
echo "- 検証ループのカウンタを 0 にリセットしました（Stop 時に api は Pint + PHPUnit、web は変更があれば tsc + ESLint が走ります）"
echo "- 要件: ../00_要件定義.md / 契約: docs/01_技術仕様_next.md / ループ: docs/04_ループエンジニアリング.md / 計画: docs/00_計画と決定事項.md"
echo "- コマンドは make 経由（make help）。api=8002 / web=3002 / mailpit=8025"

# 別の弾のサーバーを見ていないか（第2弾の教訓: 8000 番が第1弾のサーバーだった）
for p in 8002 3002; do
  pid="$(lsof -tiTCP:$p -sTCP:LISTEN 2>/dev/null | head -1)"
  [ -n "$pid" ] || continue
  cwd="$(lsof -a -p "$pid" -d cwd -Fn 2>/dev/null | sed -n 's/^n//p')"
  case "$cwd" in
    "$ROOT"/*) ;;
    *) echo "- **警告**: ポート $p は別の場所のプロセスが使っています（cwd=${cwd}）。手動確認の前に止めてください" ;;
  esac
done

# 画面の確認には redis / mailpit が要る（テストは要らない）
if ! docker compose ps --status running 2>/dev/null | grep -q redis; then
  echo "- **注意**: redis が起動していません。画面で確かめるなら make up を実行してください（キュー・Horizon・キャッシュが使えません）"
fi

if git rev-parse --git-dir >/dev/null 2>&1 && git rev-parse HEAD >/dev/null 2>&1; then
  echo "- 直近のコミット:"
  git log -5 --oneline 2>/dev/null | sed 's/^/    /'
else
  echo "- まだコミットがありません"
fi

# タスクの状態は GitHub Issues が正（issue #4）。ブランチ issue/<番号> なら今の issue を出す
BRANCH="$(git branch --show-current 2>/dev/null)"
ISSUE_NO="$(printf '%s' "$BRANCH" | sed -nE 's#^issue/([0-9]+)(-.*)?$#\1#p')"
if [ -n "$ISSUE_NO" ]; then
  echo "- 今のブランチ ${BRANCH} は issue #${ISSUE_NO} の作業。タスク表は gh issue view ${ISSUE_NO} の本文のチェックリスト"
fi
if command -v gh >/dev/null 2>&1; then
  # オフラインや未認証で起動が止まらないよう、5 秒で諦める（macOS には timeout がないので perl の alarm）
  ISSUES="$(perl -e 'alarm shift; exec @ARGV' 5 gh issue list --state open --limit 10 --json number,title,labels \
    --jq '.[] | "#\(.number) \(.title)\(if any(.labels[]; .name == "requires-human") then "（requires-human）" else "" end)"' 2>/dev/null)"
  if [ -n "$ISSUES" ]; then
    echo "- open の issue（GitHub。タスクの正）:"
    printf '%s\n' "$ISSUES" | sed 's/^/    /'
  else
    echo "- open の issue を取得できませんでした（0 件、またはオフライン）。gh issue list で確認してください"
  fi
fi
exit 0
