# next_team の入口。リード（next_team/ で起動した Claude Code）も人もここから叩く。
# api/ と web/ に cd しなくて済むようにするのが目的（フックと permissions もこのターゲット名を前提にしている）。
# ポート: api=8002 / web=3002 / mailpit=8025 / redis=6379（8000・8001・3000 は他のプロジェクトが使うので避ける）

.PHONY: help up down ps api-serve web-dev horizon schedule fresh \
        api-test api-pint api-pint-test api-routes web-typecheck web-lint web-format web-format-check web-build \
        verify verify-full worktree-env dev-stop ports mobile-analyze mobile-run dev-up dev-down dev-status

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

# --- まとめて起動（issue #46）。今いる場所（main / issue の worktree / review）に合わせたポートで、
#     Redis・Mailpit・API・管理画面・キューのワーカー（Horizon）をそろえる。起動済みのものはそのまま使う ---
dev-up: ## 開発環境をまとめて起動（Redis・Mailpit・API・管理画面・Horizon。ポートは場所で決まる。make dev-status で確認）
	@scripts/dev.sh up
dev-down: ## dev-up で起動したこの場所の API・管理画面・Horizon を止める（Redis・Mailpit は make down）
	@scripts/dev.sh down
dev-status: ## この場所のポートと、動いているものを出す
	@scripts/dev.sh status

# --- 1 つずつ起動（それぞれ別ターミナルで。main の作業ツリー向け） ---
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
# 環境をそろえる・アプリを起動するのは scripts/dev.sh（場所でポートを決める。main 8002/3002、issue #N 8100+N/3100+N、review 8012/3012）
mobile-run: ## 環境（dev-up）をそろえ、シミュレータを起動して、その場所の API につないでアプリを起動（r でホットリロード、q で終了）
	@MOBILE_DEVICE="$(MOBILE_DEVICE)" scripts/dev.sh mobile

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
