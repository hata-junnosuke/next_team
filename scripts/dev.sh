#!/bin/bash
# next_team の開発環境を、今いる場所に合わせて 1 つのコマンドでそろえる（issue #46）。
# make dev-up / dev-down / dev-status / mobile-run から呼ぶ。
#
#   up      Redis・Mailpit（共有）、API、管理画面、キューのワーカー（Horizon）、スケジューラを起動する。起動済みのものはそのまま使う
#   mobile  up のあと、iOS シミュレータを起動し、その場所の API につないでワーカーアプリを起動する（flutter run）
#   down    この場所の API・管理画面・Horizon・スケジューラを止める（Redis・Mailpit は共有なので止めない。止めるのは make down）
#   status  この場所のポートと、動いているものを出す
#
# ポートは場所で決める（ほかの場所とぶつからない。.claude/skills/issue-flow/dev-server.md と /pr-env と同じ決まり）:
#   main の作業ツリー        API 8002        管理画面 3002
#   issue #N の worktree     API 8100 + N    管理画面 3100 + N   （ブランチ issue/N）
#   review の worktree       API 8012        管理画面 3012       （/pr-env）
#   共有                     Redis 6379      Mailpit 8025（SMTP 1025）
# worktree では、.env を書き換えずに、起動時の環境変数で URL と Redis の prefix を上書きする（キューやキャッシュが混ざらない）。
# セッションの Cookie 名（SESSION_COOKIE）は上書きしない（web/proxy.ts が名前を決め打ちで見ている。issue #24）。
# サーバーは nohup で切り離して動かすので、このコマンドが終わっても残る。ログは api/storage/logs/dev-*.log。
set -u

ROOT=$(cd "$(dirname "$0")/.." && pwd -P)
cd "$ROOT" || exit 1
LOG="$ROOT/api/storage/logs"
MOBILE_SIM=${MOBILE_SIM:-iPhone 17}
MOBILE_DEVICE=${MOBILE_DEVICE:-iPhone}

# --- 場所とポートを決める ---
git_dir=$(git rev-parse --path-format=absolute --git-dir)
common_dir=$(git rev-parse --path-format=absolute --git-common-dir)
branch=$(git branch --show-current)
PREFIX=""
if [ "$git_dir" = "$common_dir" ]; then
  PLACE="main の作業ツリー"; API_PORT=8002; WEB_PORT=3002
elif [[ "$branch" =~ ^issue/([1-9][0-9]*)$ ]]; then
  n=${BASH_REMATCH[1]}
  PLACE="issue #$n の worktree"; API_PORT=$((8100 + n)); WEB_PORT=$((3100 + n)); PREFIX="next-team-issue-$n"
elif [ "$branch" = "review" ]; then
  PLACE="review の worktree"; API_PORT=8012; WEB_PORT=3012; PREFIX="next-team-review"
else
  echo "どのポートを使うか決められません（ブランチ: ${branch:-なし}）。main・issue/<番号>・review のどれかで使ってください" >&2
  exit 1
fi
API_URL="http://localhost:$API_PORT"
WEB_URL="http://localhost:$WEB_PORT"

# API と Horizon に渡す環境変数（main は .env のまま）
api_env=()
if [ -n "$PREFIX" ]; then
  api_env=(APP_URL="$API_URL" FRONTEND_URL="$WEB_URL" SANCTUM_STATEFUL_DOMAINS="localhost:$WEB_PORT"
    REDIS_PREFIX="$PREFIX-database-" CACHE_PREFIX="$PREFIX-cache-" HORIZON_PREFIX="$PREFIX-horizon:")
fi

# --- 小さな道具 ---
cwd_of() { lsof -a -p "$1" -d cwd -Fn 2>/dev/null | sed -n 's/^n//p'; }
in_root() { case "$1/" in "$ROOT"/*) return 0 ;; esac; return 1; }
listener() { lsof -tiTCP:"$1" -sTCP:LISTEN 2>/dev/null | head -1; }
# この場所の artisan のコマンド（horizon / schedule:work）の pid
artisan_pids() {
  for p in $(pgrep -f "artisan $1\$" 2>/dev/null); do
    [ "$(cwd_of "$p")" = "$ROOT/api" ] && echo "$p"
  done
}
wait_http() { # URL 秒数
  for _ in $(seq 1 "$2"); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$1" 2>/dev/null)" = 200 ] && return 0
    sleep 1
  done
  return 1
}

# ポートが空いていれば起動する。この場所のものが動いていれば使う。別の場所のものなら止まる。
# 外側の ( ) ごとログに向けるのは、呼んだ側の出力（パイプ）を持ったシェルが残って、make が終わらなくなるのを防ぐため。
start_api() {
  local pid; pid=$(listener "$API_PORT")
  if [ -n "$pid" ]; then
    in_root "$(cwd_of "$pid")" || { echo "API $API_PORT は別の場所が使っています（pid ${pid}、$(cwd_of "$pid")）" >&2; return 1; }
    echo "API         ${API_URL}（起動済み）"; return 0
  fi
  # php artisan serve は子プロセスに環境変数を渡さないので、php -S で直接起動する（/pr-env と同じ）
  (cd api/public && exec env ${api_env[@]+"${api_env[@]}"} nohup php -S "localhost:$API_PORT" \
    ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) </dev/null >"$LOG/dev-api.log" 2>&1 &
  echo "API         ${API_URL}（起動しました。ログ api/storage/logs/dev-api.log）"
}

start_web() {
  local pid; pid=$(listener "$WEB_PORT")
  if [ -n "$pid" ]; then
    in_root "$(cwd_of "$pid")" || { echo "管理画面 $WEB_PORT は別の場所が使っています（pid ${pid}、$(cwd_of "$pid")）" >&2; return 1; }
    echo "管理画面    ${WEB_URL}（起動済み）"; return 0
  fi
  # Next.js は同じフォルダの next dev を 2 つ動かせない。この場所の next dev が別のポートで残っていれば知らせて止まる
  for p in $(lsof -tiTCP -sTCP:LISTEN 2>/dev/null | sort -u); do
    if [ "$(cwd_of "$p")" = "$ROOT/web" ]; then
      echo "この場所の next dev が別のポートで動いています（pid ${p}）。make dev-down で止めてからやり直してください" >&2
      return 1
    fi
  done
  (cd web && exec env API_URL="$API_URL" NEXT_PUBLIC_API_URL="$API_URL" WEB_ORIGIN="$WEB_URL" \
    nohup npm run dev -- --port "$WEB_PORT") </dev/null >"$LOG/dev-web.log" 2>&1 &
  echo "管理画面    ${WEB_URL}（起動しました。ログ api/storage/logs/dev-web.log）"
}

# キューのワーカー。招待メール・確定メールなどはキューに積まれるので、これがないと Mailpit に届かない
start_horizon() {
  if [ -n "$(artisan_pids horizon)" ]; then echo "キュー      Horizon（起動済み）"; return 0; fi
  (cd api && exec env ${api_env[@]+"${api_env[@]}"} nohup php artisan horizon) </dev/null >"$LOG/dev-horizon.log" 2>&1 &
  echo "キュー      Horizon（起動しました。ログ api/storage/logs/dev-horizon.log）"
}

# スケジューラ（F-22 の前日リマインド 毎日 18:00、horizon:snapshot 5 分ごと など。api/routes/console.php）
start_schedule() {
  if [ -n "$(artisan_pids schedule:work)" ]; then echo "スケジューラ schedule:work（起動済み）"; return 0; fi
  (cd api && exec env ${api_env[@]+"${api_env[@]}"} nohup php artisan schedule:work) </dev/null >"$LOG/dev-schedule.log" 2>&1 &
  echo "スケジューラ schedule:work（起動しました。ログ api/storage/logs/dev-schedule.log）"
}

up() {
  echo "== $PLACE の開発環境（API $API_PORT / 管理画面 ${WEB_PORT}）=="
  for f in api/.env web/.env.local api/database/database.sqlite api/vendor web/node_modules; do
    [ -e "$f" ] || { echo "$f がありません（worktree なら /issue-start の準備、DB は make fresh）" >&2; exit 1; }
  done
  docker compose -p next_team up -d >/dev/null 2>&1 \
    || { echo "Redis・Mailpit を起動できません。Docker Desktop が動いているか確かめてください" >&2; exit 1; }
  echo "Redis       localhost:6379（共有）"
  echo "Mailpit     http://localhost:8025（共有）"
  start_api || exit 1
  start_web || exit 1
  start_horizon
  start_schedule
  printf "API の応答を待っています"
  wait_http "$API_URL/up" 60 && echo " → OK" || { echo " → 応答なし（api/storage/logs/dev-api.log）" >&2; exit 1; }
  printf "管理画面の応答を待っています（初回はビルドで時間がかかります）"
  wait_http "$WEB_URL/login" 120 && echo " → OK" || { echo " → 応答なし（api/storage/logs/dev-web.log）" >&2; exit 1; }
  local code
  code=$(sqlite3 api/database/database.sqlite "select code from companies order by id limit 1" 2>/dev/null)
  echo ""
  echo "ログイン"
  echo "  管理画面    $WEB_URL  admin@example.com / password"
  echo "  アプリ      企業コード ${code:-（管理画面のワーカー一覧の上）} / worker@example.com / password"
}

mobile() {
  up
  if xcrun simctl list devices booted | grep -q '(Booted)'; then
    echo "シミュレータ 起動済みを使います"
  else
    xcrun simctl boot "$MOBILE_SIM" && echo "シミュレータ $MOBILE_SIM を起動しました"
  fi
  # 画面を出す（Xcode 27 は DeviceHub、それより前は Simulator）
  open /Applications/Xcode.app/Contents/Applications/DeviceHub.app 2>/dev/null || open -a Simulator 2>/dev/null || true
  echo "アプリ      $API_URL につないで起動します（r でホットリロード、R でホットリスタート、q で終了）"
  cd mobile && exec flutter run -d "$MOBILE_DEVICE" --dart-define=API_BASE_URL="$API_URL"
}

stop_port() { # ポート 名前
  local pid cwd parent
  for pid in $(lsof -tiTCP:"$1" -sTCP:LISTEN 2>/dev/null); do
    cwd=$(cwd_of "$pid")
    if ! in_root "$cwd"; then echo "$2 $1: 別の場所のものなので止めません（pid ${pid}、${cwd}）"; continue; fi
    parent=$(ps -o ppid= -p "$pid" | tr -d ' ')
    kill "$pid" && echo "$2 $1: 止めました（pid ${pid}）"
    # next dev は子が待ち受けるので、親の npm / next も同じ場所なら止める
    if [ -n "$parent" ] && [ "$parent" != 1 ] && in_root "$(cwd_of "$parent")" \
      && ps -o command= -p "$parent" | grep -qE 'npm|next'; then
      kill "$parent" 2>/dev/null
    fi
  done
}

down() {
  echo "== $PLACE の開発環境を止めます =="
  stop_port "$API_PORT" "API"
  stop_port "$WEB_PORT" "管理画面"
  local p
  for p in $(artisan_pids horizon); do kill "$p" && echo "Horizon: 止めました（pid ${p}）"; done
  for p in $(artisan_pids schedule:work); do kill "$p" && echo "スケジューラ: 止めました（pid ${p}）"; done
  echo "Redis・Mailpit は共有なので残します（止めるなら make down）"
}

status() {
  echo "== ${PLACE}（API $API_PORT / 管理画面 ${WEB_PORT}）=="
  local port pid
  for port in "$API_PORT" "$WEB_PORT"; do
    pid=$(listener "$port")
    if [ -n "$pid" ]; then echo "$port: pid ${pid}（$(cwd_of "$pid")）"; else echo "$port: 空き"; fi
  done
  [ -n "$(artisan_pids horizon)" ] && echo "Horizon: 起動中" || echo "Horizon: 止まっている"
  [ -n "$(artisan_pids schedule:work)" ] && echo "スケジューラ: 起動中" || echo "スケジューラ: 止まっている"
  docker ps --filter label=com.docker.compose.project=next_team --format '{{.Names}}  {{.Status}}' 2>/dev/null || true
}

case "${1:-}" in
  up) up ;;
  mobile) mobile ;;
  down) down ;;
  status) status ;;
  *) echo "使い方: scripts/dev.sh up|mobile|down|status" >&2; exit 1 ;;
esac
