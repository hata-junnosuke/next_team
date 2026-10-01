#!/usr/bin/env bash
# SessionStart フック: プロジェクトの現在地を Claude に伝える（5+1 の「メモリ」を読み戻す入口）
# 入力: stdin JSON {"source":"startup|resume|compact", ...}
# 出力: stdout の文章がそのままセッションのコンテキストに追加される
set -u
ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
cd "$ROOT" || exit 0

PHASE="$(cat .claude/.phase 2>/dev/null || echo 0)"
case "$PHASE" in
  0) PHASE_NAME="ハーネス構築・ゲート確認" ;;
  1) PHASE_NAME="API 骨格（Sanctum + Fortify、拠点を縦に 1 本）" ;;
  2) PHASE_NAME="ドメイン（API をゼロから実装）" ;;
  3) PHASE_NAME="フロント（動線 10 ステップの画面）" ;;
  4) PHASE_NAME="非同期（Queue / Job / スケジューラ / バッチ）" ;;
  5) PHASE_NAME="仕上げ（通知履歴・ドキュメント）" ;;
  6) PHASE_NAME="Could（Broadcasting / Reverb）" ;;
  *) PHASE_NAME="不明" ;;
esac

# 新しいセッションでは検証ループの回数を 0 に戻す（前セッションの途中状態を引きずらない）
rm -f .claude/.loop-count .claude/.subagent-loop-count .claude/.subagent-loop-count.*
# 担当が動いている印も消す（前セッションの異常終了で残ると、Stop フックの検査が飛ばされ続ける）。
# ただし圧縮（compact）のときは消さない。担当がまだ動いている最中に圧縮されることがあるため
SOURCE="$(jq -r '.source // "startup"' 2>/dev/null || echo startup)"
[ "$SOURCE" = "compact" ] || rm -rf .claude/.running

TEST_COUNT="$(grep -rhoE 'function test_[^ (]+' api/tests 2>/dev/null | wc -l | tr -d ' ')"
DIRTY="$(git status --short 2>/dev/null | wc -l | tr -d ' ')"

echo "## next_team セッション開始情報"
echo "- 起動場所: ${ROOT}（ここが next_team/ なら Stop フックが効きます）"
case "$ROOT" in
  */next_team) ;;
  *) echo "- **警告**: 起動場所が next_team/ ではありません。フックの相対パスがずれます。cd next_team && claude で入り直してください" ;;
esac
echo "- 現在フェーズ: ${PHASE}（${PHASE_NAME}）。フェーズ移行は人が .claude/.phase を書き換える"
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

# 非同期フェーズ以降は redis / mailpit が要る
if [ "$PHASE" -ge 4 ]; then
  if ! docker compose ps --status running 2>/dev/null | grep -q redis; then
    echo "- **注意**: redis が起動していません。make up を実行してください（キュー・Horizon・キャッシュが使えません）"
  fi
fi

if git rev-parse --git-dir >/dev/null 2>&1 && git rev-parse HEAD >/dev/null 2>&1; then
  echo "- 直近のコミット:"
  git log -5 --oneline 2>/dev/null | sed 's/^/    /'
else
  echo "- まだコミットがありません"
fi

if [ -f .claude/state/STATE.md ]; then
  echo "- 進行中のタスク（.claude/state/STATE.md より）:"
  grep -E '^\| .* \| (進行中|レビュー中|requires-human) \|' .claude/state/STATE.md | head -10 | sed 's/^/    /' || true
fi

if [ -f .claude/state/learnings.md ]; then
  echo "- 直近の教訓（.claude/state/learnings.md 末尾）:"
  grep -E '^- [0-9]{4}-' .claude/state/learnings.md | tail -5 | sed 's/^/    /' || true
fi
exit 0
