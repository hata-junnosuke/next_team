# next_team の入口。リード（next_team/ で起動した Claude Code）も人もここから叩く。
# api/ と web/ に cd しなくて済むようにするのが目的（フックと permissions もこのターゲット名を前提にしている）。
# ポート: api=8002 / web=3002 / mailpit=8025 / redis=6379（8000・8001・3000 は他のプロジェクトが使うので避ける）

.PHONY: help up down ps api-serve web-dev horizon schedule fresh \
        api-test api-pint api-pint-test api-routes web-typecheck web-lint web-format web-format-check web-build \
        verify verify-full worktree-env dev-stop ports mobile-analyze mobile-env mobile-run mobile-down

help: ## ターゲット一覧
	@grep -E '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-18s %s\n", $$1, $$2}'

# --- 周辺サービス ---
# compose のプロジェクト名を next_team に固定する。省くとフォルダ名（worktree なら issue-46 など）になり、
# main が起動したコンテナと別物として 6379 / 8025 を取り合って失敗する（issue #46）。Redis と Mailpit は全 worktree で共有する
COMPOSE := docker compose -p next_team
up: ## redis / mailpit を起動（起動済みなら何もしない。どの worktree からでも同じコンテナを使う）
	$(COMPOSE) up -d
down: ## redis / mailpit を停止（ボリュームは残す）
	$(COMPOSE) down
ps: ## コンテナの状態
	$(COMPOSE) ps

# --- 起動（それぞれ別ターミナルで） ---
api-serve: ## Laravel API を http://localhost:8002 で起動
	cd api && php artisan serve --port=8002
web-dev: ## Next.js を http://localhost:3002 で起動
	cd web && npm run dev -- --port 3002
horizon: ## キューワーカー（http://localhost:8002/horizon）
	cd api && php artisan horizon
schedule: ## スケジューラをフォアグラウンドで回す
	cd api && php artisan schedule:work
fresh: ## DB を作り直してシードを入れる
	cd api && php artisan migrate:fresh --seed

# --- 検証（Stop フックが回すものと同じ） ---
api-test: ## PHPUnit。部分実行は make api-test FILTER=ApplicationTest
	cd api && php artisan test --compact $(if $(FILTER),--filter '$(FILTER)',)
api-pint: ## Pint で整形
	cd api && vendor/bin/pint
api-pint-test: ## Pint の整形チェック（書き換えない）
	cd api && vendor/bin/pint --test
api-routes: ## ルート一覧
	cd api && php artisan route:list
web-typecheck: ## TypeScript の型チェック（先に next typegen で LayoutProps などのルートの型を生成する。.next/ がない worktree と CI 用）
	cd web && npx next typegen && npx tsc --noEmit
web-lint: ## ESLint
	cd web && npm run lint
web-format: ## Prettier で整形
	cd web && npx prettier --write .
web-format-check: ## Prettier の整形チェック
	cd web && npx prettier --check .
web-build: ## 本番ビルド（遅いので Stop フックでは回さない。/verify full で回す）
	cd web && npm run build

verify: api-pint-test api-test web-typecheck web-lint ## Stop フック相当を手で回す
verify-full: verify web-format-check web-build ## + 整形チェックと本番ビルド

# --- mobile（ワーカーアプリ。Step 9 までは verify に入れない。docs/01 の 7A.2） ---
# 機種は名前の前方一致で選ぶ（flutter devices の名前）。別の機種なら make mobile-run MOBILE_DEVICE="iPhone 17 Pro"
MOBILE_DEVICE ?= iPhone
mobile-analyze: ## flutter analyze（警告 0 件がコミットの条件）
	cd mobile && flutter analyze
# make mobile-run 1 つで、試すのに要るものを全部そろえてからアプリを起動する（issue #46）:
#   1. up（redis / mailpit。初回登録の招待メールを Mailpit で見る）
#   2. API（8002）と管理画面（3002）を、空いていればこの作業ツリーから切り離して起動する（ログは api/storage/logs/mobile-*.log）。
#      すでに誰かが使っていれば、そのまま使う（どの場所のサーバーかを出す。アプリと管理画面は同じ DB を見ること）。
#      外側の ( ) ごとログに向けるのは、make の出力（パイプ）を持ったシェルが残って、呼んだ側が終わらなくなるのを防ぐため。
#      Next.js は同じフォルダの next dev を 2 つ動かせないので、issue の画面（3100 + 番号）が動いていれば、その URL を出す
#   3. 起動しているシミュレータがなければ $(MOBILE_SIM) を起動して画面を出す
#   4. API が応答するのを待ってから flutter run（q で終了しても、API・管理画面・シミュレータは残る。止めるのは make mobile-down）
MOBILE_SIM ?= iPhone 17
MOBILE_LOG := api/storage/logs
mobile-env: up ## mobile-run の下ごしらえ（API 8002・管理画面 3002・シミュレータ。起動済みならそのまま使う）
	@test -f api/.env && test -f web/.env.local && test -f api/database/database.sqlite && test -d api/vendor && test -d web/node_modules \
	  || { echo "api/.env・web/.env.local・DB・vendor・node_modules のどれかがありません（worktree なら /issue-start の準備、DB は make fresh）"; exit 1; }
	@cwd_of() { lsof -a -p "$$1" -d cwd -Fn 2>/dev/null | sed -n 's/^n//p'; }; \
	pid=$$(lsof -tiTCP:8002 -sTCP:LISTEN 2>/dev/null | head -1); \
	if [ -n "$$pid" ]; then echo "API 8002: 起動済みを使います（$$(cwd_of $$pid)）"; \
	else (cd api && exec nohup php artisan serve --port=8002) < /dev/null > $(MOBILE_LOG)/mobile-api.log 2>&1 & \
	  echo "API 8002: 起動しました（ログ $(MOBILE_LOG)/mobile-api.log）"; fi; \
	webdir=$$(pwd -P)/web; other=""; \
	for p in $$(lsof -tiTCP -sTCP:LISTEN 2>/dev/null | sort -u); do \
	  [ "$$(cwd_of $$p)" = "$$webdir" ] || continue; \
	  port=$$(lsof -a -p $$p -iTCP -sTCP:LISTEN -P -n -Fn 2>/dev/null | sed -n 's/^n.*://p' | head -1); \
	  [ -n "$$port" ] && other=$$port && break; done; \
	pid=$$(lsof -tiTCP:3002 -sTCP:LISTEN 2>/dev/null | head -1); \
	if [ -n "$$pid" ]; then echo "管理画面 3002: 起動済みを使います（$$(cwd_of $$pid)）"; \
	elif [ -n "$$other" ]; then echo "管理画面: この作業ツリーの next dev がすでに http://localhost:$${other} で動いているので、そちらを使ってください（Next.js は同じフォルダで 2 つ動かせない。DB は同じ）"; \
	else (cd web && exec nohup npm run dev -- --port 3002) < /dev/null > $(MOBILE_LOG)/mobile-web.log 2>&1 & \
	  for i in $$(seq 1 30); do \
	    lsof -tiTCP:3002 -sTCP:LISTEN >/dev/null 2>&1 && { echo "管理画面 3002: 起動しました（ログ $(MOBILE_LOG)/mobile-web.log）"; break; }; \
	    sleep 1; done; fi
	@if xcrun simctl list devices booted | grep -q '(Booted)'; then echo "シミュレータ: 起動済みを使います"; \
	else xcrun simctl boot "$(MOBILE_SIM)" && echo "シミュレータ: $(MOBILE_SIM) を起動しました"; fi; \
	open /Applications/Xcode.app/Contents/Applications/DeviceHub.app 2>/dev/null || open -a Simulator 2>/dev/null || true
	@printf "API の応答を待っています"; for i in $$(seq 1 30); do \
	  curl -s -o /dev/null -w '%{http_code}' http://localhost:8002/up 2>/dev/null | grep -q 200 && { echo " → OK"; exit 0; }; printf "."; sleep 1; done; \
	echo " → 30 秒待っても応答がありません（$(MOBILE_LOG)/mobile-api.log を見てください）"; exit 1
mobile-run: mobile-env ## 環境をそろえて（mobile-env）、iOS シミュレータで mobile/ を起動（r でホットリロード、q で終了）
	cd mobile && flutter run -d "$(MOBILE_DEVICE)" --dart-define=API_BASE_URL=http://localhost:8002
mobile-down: ## mobile-env が起動した API（8002）と管理画面（3002）を止める（この作業ツリーのものだけ。redis / mailpit は make down）
	@here=$$(pwd -P); for port in 8002 3002; do \
	  for pid in $$(lsof -tiTCP:$$port -sTCP:LISTEN 2>/dev/null); do \
	    cwd=$$(lsof -a -p $$pid -d cwd -Fn 2>/dev/null | sed -n 's/^n//p'); \
	    case "$$cwd/" in "$$here"/*) kill $$pid && echo "$${port}: pid $${pid} を止めました（$${cwd}）";; \
	      *) echo "$${port}: 別の場所のサーバーなので止めません（pid $${pid}、$${cwd}）";; esac; \
	  done; done

# --- worktree ---
# main の作業ツリー（git worktree list の先頭）の .env / .env.local を WT にコピーする。中身は表示しない。
# すでにあるファイルは上書きしない。DB_DATABASE が絶対パスだと worktree が main の DB を共有するので、値を出さずに失敗する
MAIN_TREE := $(shell git worktree list --porcelain 2>/dev/null | sed -n '1s/^worktree //p')
worktree-env: ## main の api/.env と web/.env.local を worktree にコピー（make worktree-env WT=<場所>）
	@test -n "$(WT)" || { echo "WT=<worktree の場所> を指定してください"; exit 1; }
	@test -d "$(WT)/api" && test -d "$(WT)/web" || { echo "$(WT) に api/ と web/ がありません"; exit 1; }
	@test "$$(cd "$(WT)" && pwd -P)" != "$$(cd "$(MAIN_TREE)" && pwd -P)" || { echo "WT が main の作業ツリーです"; exit 1; }
	@test -f "$(MAIN_TREE)/api/.env" || { echo "main に api/.env がありません"; exit 1; }
	@if grep -Eq '^DB_DATABASE=["'\'']?/' "$(MAIN_TREE)/api/.env"; then \
	  echo "main の api/.env の DB_DATABASE が絶対パスです（値は表示しません）。コピーすると main と DB を共有するので止めました。人が相対パスか未設定にしてください"; exit 1; fi
	@if [ -f "$(WT)/api/.env" ]; then echo "api/.env: すでにあるのでそのまま"; else cp "$(MAIN_TREE)/api/.env" "$(WT)/api/.env" && echo "api/.env: コピーしました"; fi
	@if [ -f "$(WT)/web/.env.local" ]; then echo "web/.env.local: すでにあるのでそのまま"; \
	elif [ -f "$(MAIN_TREE)/web/.env.local" ]; then cp "$(MAIN_TREE)/web/.env.local" "$(WT)/web/.env.local" && echo "web/.env.local: コピーしました"; \
	else echo "main に web/.env.local がありません"; exit 1; fi
	@if grep -Eq '^DB_DATABASE=["'\'']?/' "$(WT)/api/.env"; then \
	  echo "$(WT)/api/.env の DB_DATABASE が絶対パスです（値は表示しません）。人が直してください"; exit 1; fi

# --- issue ごとの画面（.claude/skills/issue-flow/dev-server.md） ---
# API 8100+N / web 3100+N で listen している pid を、cwd が WT の中のときだけ止める。
# web で listen しているのは next dev の子なので、親の next dev / npm run dev も、cwd が WT の中なら止める。
# 場所が違うものは止めずに pid と cwd を出して 1 で終わる（別の worktree や別のプロジェクトのサーバーを止めない）
# WT と N はレシピに文字として埋め込まず、環境変数（$$WT / $$N）で読む（引用符を閉じてコマンドを足す注入を防ぐ）。
# WT は git worktree list にある main 以外の worktree と完全に一致するときだけ受け付ける（親のフォルダや $$HOME を渡して広く止めない）
dev-stop: ## issue ごとの画面のサーバーを止める（make dev-stop WT=<worktree の場所> N=<issue 番号>）
	@test -n "$$WT" && test -n "$$N" || { echo "WT=<worktree の場所> N=<issue 番号> を指定してください"; exit 1; }
	@case "$$N" in [1-9]|[1-9]*[0-9]) case "$$N" in *[!0-9]*) echo "N は issue 番号（先頭が 0 でない数字）です"; exit 1;; esac;; \
	  *) echo "N は issue 番号（先頭が 0 でない数字）です"; exit 1;; esac
	@test -d "$$WT" || { echo "WT の場所がありません: $$WT"; exit 1; }
	@wt=$$(cd "$$WT" && pwd -P); \
	main=$$(git worktree list --porcelain | sed -n '1s/^worktree //p'); main=$$(cd "$$main" && pwd -P); \
	found=""; for w in $$(git worktree list --porcelain | sed -n 's/^worktree //p' | tr ' ' '\001'); do \
	  w=$$(printf '%s' "$$w" | tr '\001' ' '); [ "$$(cd "$$w" 2>/dev/null && pwd -P)" = "$$wt" ] && found=1; done; \
	[ -n "$$found" ] || { echo "WT がこのリポジトリの worktree ではありません（git worktree list と完全に一致する場所を渡してください）: $$wt"; exit 1; }; \
	[ "$$wt" != "$$main" ] || { echo "WT が main の作業ツリーです"; exit 1; }; \
	cwd_of() { lsof -a -p "$$1" -d cwd -Fn 2>/dev/null | sed -n 's/^n//p'; }; \
	in_wt() { case "$$1/" in "$$wt"/*) return 0;; esac; return 1; }; \
	ports="$$((8100 + N)) $$((3100 + N))"; rc=0; stopped=""; \
	for port in $$ports; do \
	  pids=$$(lsof -tiTCP:$$port -sTCP:LISTEN 2>/dev/null | sort -u); \
	  if [ -z "$$pids" ]; then echo "$$port: 空き"; continue; fi; \
	  for pid in $$pids; do \
	    cwd=$$(cwd_of $$pid); \
	    if ! in_wt "$$cwd"; then echo "$$port: pid $$pid は $$wt の外（cwd=$${cwd}）なので止めません"; rc=1; continue; fi; \
	    parents=""; p=$$pid; \
	    for i in 1 2 3 4; do \
	      p=$$(ps -o ppid= -p $$p 2>/dev/null | tr -d ' '); [ -n "$$p" ] && [ "$$p" -gt 1 ] || break; \
	      case "$$(ps -o command= -p $$p 2>/dev/null)" in *"next dev"*|*next-server*|*"npm run dev"*) parents="$$parents $$p";; *) break;; esac; \
	    done; \
	    if kill $$pid; then echo "$$port: pid $$pid を止めました（cwd=$${cwd}）"; stopped="$$stopped $$port"; \
	    else echo "$$port: pid $$pid を止められません（cwd=$${cwd}）"; rc=1; continue; fi; \
	    for p in $$parents; do \
	      sleep 1; kill -0 $$p 2>/dev/null || continue; \
	      pc=$$(cwd_of $$p); pcmd=$$(ps -o command= -p $$p 2>/dev/null | cut -c1-60); \
	      if in_wt "$$pc"; then \
	        if kill $$p; then echo "$$port: 残っていた親の pid $$p も止めました（$${pcmd}）"; else echo "$$port: 親の pid $$p を止められません"; rc=1; fi; \
	      else echo "$$port: 親の pid $$p は $$wt の外（cwd=$${pc}）なので止めません"; rc=1; fi; \
	    done; \
	  done; \
	done; \
	for i in 1 2 3 4 5 6 7 8 9 10; do \
	  busy=""; for port in $$stopped; do lsof -tiTCP:$$port -sTCP:LISTEN >/dev/null 2>&1 && busy="$$busy $$port"; done; \
	  [ -z "$$busy" ] && break; sleep 1; \
	done; \
	[ -z "$$busy" ] || { echo "まだ listen しているポート:$$busy"; rc=1; }; \
	exit $$rc

ports: ## 8002 / 3002 を誰が使っているか（別のプロジェクトのサーバーを見ていないかの確認）
	@for p in 8002 3002; do pid=$$(lsof -tiTCP:$$p -sTCP:LISTEN 2>/dev/null | head -1); \
	  if [ -n "$$pid" ]; then echo "$$p: pid $$pid cwd=$$(lsof -a -p $$pid -d cwd -Fn 2>/dev/null | sed -n 's/^n//p')"; else echo "$$p: 空き"; fi; done
