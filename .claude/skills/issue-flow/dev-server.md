# issue の画面を起動して Orca のブラウザで開く

`/issue-start`（worktree を作った直後）・`/epic-flow` `/issue-flow`（作業の始め）・`/pr-create`（CI が緑になった後）から使う手順。**Orca を使用している場合だけ**行う。環境変数 `ORCA_WORKTREE_ID` が空なら（Orca の外なら）何もしない。

worktree（`issue/<番号>`）のコードを、開発（8002 / 3002。main の作業ツリーなどが使う）とも `/pr-env`（8012 / 3012）ともぶつからない **issue ごとのポート**で起動する。3002 は別のブランチの画面のことが多いので、開かない。

| | ポート（issue 番号 N） | 例: issue #3 |
|---|---|---|
| API | `8100 + N` | 8103 |
| web | `3100 + N` | 3103 |

サーバーは **`nohup` でシェルから切り離して**動かす。Orca のターミナルのタブは作らない（ログのタブは要らない。人が選択）。Claude の `run_in_background` も使わない（セッションが終わると止まる）。ログは worktree の `api/storage/logs/dev-api.log` と `dev-web.log`（git の管理外）に出す。止めるのは `/dev-refresh` か、人に言われたとき（下の「止める」）。

ポートと Redis の prefix は `.env` を書き換えず、起動時の環境変数で上書きする（`php artisan serve` は子プロセスに環境変数を渡さないので `php -S` を使う）。Mailpit（8025）と Redis は共有。

**セッションの Cookie 名（`SESSION_COOKIE`）は上書きしない。** web の `web/proxy.ts` が Cookie 名を `next-team-session` と決め打ちで見ているので、API だけ名前を変えると、ログインしても「Cookie がない」と判定されてログイン画面に戻される（issue #24）。その代償として、同じブラウザで別のポートの画面を開くとセッションが上書きされる（下の「注意」）。セッションの中身は worktree ごとの DB（`SESSION_DRIVER=database`）にあるので、別の worktree のログインが混ざることはない（`APP_KEY` は main からコピーした同じ値なので、`SESSION_DRIVER` を `cookie` に変えると混ざる。変えない）。

## 手順
`<WT>` は worktree の絶対パス、`N` は issue 番号、`<API>` `<WEB>` は上の表の値。CLI は `ORCA_CLI_COMMAND` があればその値、なければ `orca`。

1. 準備を確かめる（中身は読まない）: `<WT>/api/.env` `<WT>/web/.env.local` `<WT>/api/vendor` `<WT>/web/node_modules` `<WT>/api/database/database.sqlite`。ないものがあれば起動せず、`/issue-start <番号>` の準備が足りないと報告して作業は続ける
2. 2 つのポートが空いているか `lsof -tiTCP:<port> -sTCP:LISTEN` で確かめる
   - 使用中で、`lsof -a -p <pid> -d cwd` の場所が `<WT>` の中なら、起動済みとしてそのまま使う（3 を飛ばす）。ただし API が返すセッションの Cookie 名を `curl -s -D - -o /dev/null http://localhost:<API>/sanctum/csrf-cookie | grep -i '^set-cookie'` で確かめ、`next-team-session=` がなく別の名前（`next-team-issue-N-session=` など。この手順を直す前の起動で、ログインしてもログイン画面に戻される）が出たら、下の「止める」の `make dev-stop WT=<WT> N=<番号>` で止めて 3 で起動し直す
   - 別の場所のものなら kill せず、人に聞く
3. 切り離して起動する（1 回の Bash で。`( ... &)` のサブシェルで起動すると、起動したシェルが終わっても残る）
   ```bash
   (cd <WT>/api/public && APP_URL=http://localhost:<API> FRONTEND_URL=http://localhost:<WEB> SANCTUM_STATEFUL_DOMAINS=localhost:<WEB> \
     REDIS_PREFIX=next-team-issue-N-database- CACHE_PREFIX=next-team-issue-N-cache- HORIZON_PREFIX=next-team-issue-N-horizon: \
     nohup php -S localhost:<API> ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php > <WT>/api/storage/logs/dev-api.log 2>&1 &)
   (cd <WT>/web && API_URL=http://localhost:<API> NEXT_PUBLIC_API_URL=http://localhost:<API> WEB_ORIGIN=http://localhost:<WEB> \
     nohup npm run dev -- --port <WEB> > <WT>/api/storage/logs/dev-web.log 2>&1 &)
   ```
4. `http://localhost:<API>/up` と `http://localhost:<WEB>/login` が 200 を返すまで待つ（最大 90 秒。`next dev` は最初の表示でビルドするので遅い）。超えたら `dev-api.log` / `dev-web.log` の末尾を報告し、画面なしで作業を続ける
5. その worktree の Orca のブラウザで開く。`orca tab list --worktree path:<WT> --json` を見て
   - `localhost:<WEB>` のタブがあれば、そのタブで `orca goto --url http://localhost:<WEB>/login --json`（開き直し）
   - `localhost:3002` など、このブランチ以外の画面のタブがあれば、`orca tab switch --index <n>` → `orca goto` で差し替える
   - どちらもなければ `orca tab create --worktree path:<WT> --url http://localhost:<WEB>/login --json`
   - `orca` がエラーになったら、エラーを報告に書いて先に進む（作業は止めない。`runtime_access_denied` ならサンドボックスの外で再実行する）
6. 人に伝える: 「画面: http://localhost:<WEB>（`issue/<番号>` の worktree。admin@example.com / password）」。キュー・メール・キャッシュを見るなら `make up`（Redis / Mailpit）が要る。Horizon は人に聞いてから、同じ prefix で起動する（`make horizon` は使わない）

## 止める
`/dev-refresh`（worktree を消す前）と、人に言われたときだけ。生の `kill` は書かず、次のターゲットを使う（自動モードの安全判定はリードの直接の `kill <pid>` を通さないため。issue #23）
```bash
make dev-stop WT=<WT> N=<番号>
```
- `cd` や `echo` とつなげず、このコマンドだけを 1 回の Bash で打つ（`.claude/settings.json` の `Bash(make dev-stop:*)` の許可に当てはまるように）。`WT` は `git worktree list` に出る worktree の場所そのもの（main と、親のフォルダは受け付けない）。`N` は先頭が 0 でない数字
- API `8100 + N` と web `3100 + N` で listen している pid を、cwd が `<WT>` の中のときだけ止める。web は `next dev` の子が listen しているので、親の `next dev` / `npm run dev` が残っていれば、同じ確認をして止める
- 何も listen していなければ「空き」と出して成功で終わる
- 場所が違うもの（別の worktree・別のプロジェクト）は止めずに pid と cwd を出して失敗で終わる（レシピは 1 で終わり、make 自体の終了コードは 2）。そのときは止めずに人に報告する

## 注意
- ログを見るときは `tail -f <WT>/api/storage/logs/dev-api.log`（web は `dev-web.log`）
- web は `next dev` なので、実装中の変更はそのまま画面に出る。API も `php -S` なのでリクエストごとに読み直す。作業の途中で起動し直さなくてよい
- 開発のサーバー（8002 / 3002）と `/pr-env` のサーバー（8012 / 3012）には触らない
- localhost の Cookie はポートで分かれない。同じブラウザで別のポートの画面（3002・3012・ほかの issue の画面）を交互に開くと、共有の `next-team-session` と `XSRF-TOKEN` が上書きされ、ログイン画面に戻されたり 419 になったりする。そのときは開き直してログインし直す
- ログインしてもログイン画面に戻されるときは、まず API が `SESSION_COOKIE` を上書きして起動されていないかを手順 2 の確認で見る（この手順を直す前に起動したサーバーが残っていると起きる）
