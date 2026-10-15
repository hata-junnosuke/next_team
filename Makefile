# next_team の入口。リード（next_team/ で起動した Claude Code）も人もここから叩く。
# api/ と web/ に cd しなくて済むようにするのが目的（フックと permissions もこのターゲット名を前提にしている）。
# ポート: api=8002 / web=3002 / mailpit=8025 / redis=6379（8000 は第1弾、8001 は第2弾、3000 は別プロジェクトが使うので避ける）

.PHONY: help up down ps api-serve web-dev horizon schedule fresh \
        api-test api-pint api-pint-test api-routes web-typecheck web-lint web-format web-format-check web-build \
        verify verify-full ports

help: ## ターゲット一覧
	@grep -E '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-18s %s\n", $$1, $$2}'

# --- 周辺サービス ---
up: ## redis / mailpit を起動
	docker compose up -d
down: ## redis / mailpit を停止（ボリュームは残す）
	docker compose down
ps: ## コンテナの状態
	docker compose ps

# --- 起動（それぞれ別ターミナルで） ---
api-serve: ## Laravel API を http://localhost:8002 で起動
	cd api && php artisan serve --port=8002
web-dev: ## Next.js を http://localhost:3002 で起動
	cd web && npm run dev -- --port 3002
horizon: ## キューワーカー（P4 以降。http://localhost:8002/horizon）
	cd api && php artisan horizon
schedule: ## スケジューラをフォアグラウンドで回す（P4 以降）
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

ports: ## 8002 / 3002 を誰が使っているか（別の弾のサーバーを見ていないかの確認）
	@for p in 8002 3002; do pid=$$(lsof -tiTCP:$$p -sTCP:LISTEN 2>/dev/null | head -1); \
	  if [ -n "$$pid" ]; then echo "$$p: pid $$pid cwd=$$(lsof -a -p $$pid -d cwd -Fn 2>/dev/null | sed -n 's/^n//p')"; else echo "$$p: 空き"; fi; done
