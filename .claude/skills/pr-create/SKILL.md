---
name: pr-create
description: PR を .github/pull_request_template.md どおりに作る。make verify の結果を集めて本文を埋め、push して gh pr create し、CI の結果を確かめる。/epic-flow・/issue-flow の最後と、単発の作業を出すときに使う
argument-hint: <issue 番号>
---

# /pr-create — PR を作る

対象: **issue #$ARGUMENTS**

PR の **形** は `.github/pull_request_template.md` が決め、このスキルは **手順** だけを決める。見出しを変えない。

## 手順
1. 前提を確かめる
   - ブランチが `issue/<番号>` であること。`main` なら止まる
   - `git status --short` が空であること（未コミットがあれば先にコミットする）
   - `gh pr list --head issue/<番号>` で同じブランチの PR がないこと（あれば作らずに URL を返す。本文の更新は `gh pr edit`）
2. 検証を集める: `make verify` を実行し、結果を記録する
   - api: Pint 緑/赤、PHPUnit の件数（assertions）と失敗名
   - web: tsc・ESLint 緑/赤
   - **赤なら PR を作らない**。`build-error-resolver` に渡して直してから 2 に戻る
3. 本文を埋める（スクラッチパッドに一時ファイルとして書く）。テンプレートを読み、見出しはそのまま残し、`<!-- -->` は消す
   - `Closes #<番号>`
   - 変更: `git log main..HEAD --oneline` と issue のタスク ID から、タスクごとに 1〜3 行
   - 理由: 判断の理由
   - 検証: 2 の結果。レビュー（`/code-review` / issue-flow の手順 3）の重大の件数と差し戻しの周回数
   - 人の確認: 画面の手動確認（issue の完了条件に書いた項目。web の変更があれば「手動確認: 未」）、`.env` の反映、マイグレーションのロック、issue で「人が行う」とされたタスク
   - 末尾に `🤖 Generated with [Claude Code](https://claude.com/claude-code)`
4. タイトル: issue のタイトルに合わせる（`feat(api): ...` / `chore: issue #<番号> ...` など。日本語）
5. push して作る: `git push -u origin issue/<番号>` → `gh pr create --base main --title "<タイトル>" --body-file <一時ファイル>`
6. **CI の結果を確かめる**: `gh pr checks <PR 番号> --watch`（`.github/workflows/ci.yml` の `verify` ジョブ）
   - 緑: PR の URL と CI の結果を報告して止まる。**マージは人が行う**
     - **Orca を使用している場合だけ**（環境変数 `ORCA_WORKTREE_ID` が空でない＝Orca のターミナルで動いている）、報告の前に Orca のブラウザで開く。それ以外の環境では何もしない
       - `orca tab create --url <PR の URL> --json`（今の worktree のタブに開く）
       - `web/` に変更があれば、**このブランチの画面**を開く（人が画面の確認項目を見るため）。3002 は main など別の場所のサーバーのことが多いので開かない。下の「このブランチを起動して開く」に従う
       - `orca` がエラーになったら、エラーをそのまま報告に書いて先に進む（PR の作成は止めない）

## このブランチを起動して開く（Orca を使用していて、web に変更があるとき）
worktree（`issue/<番号>`）のコードを、開発（8002 / 3002）とも `/pr-env`（8012 / 3012）ともぶつからない **issue ごとのポート**で起動する。ポート・Redis の prefix・Cookie 名は `.env` を書き換えず、起動時の環境変数で上書きする（`/pr-env` と同じ方法。`php artisan serve` は環境変数を渡さないので `php -S` を使う）。

| | ポート（issue 番号 N） | 例: issue #3 |
|---|---|---|
| API | `8100 + N` | 8103 |
| web | `3100 + N` | 3103 |

1. `api/.env` `web/.env.local` `api/vendor` `web/node_modules` `api/database/database.sqlite` があるか確かめる（中身は読まない）。ないものがあれば起動せず、`/issue-start` の準備が足りないと報告する
2. 2 つのポートが空いているか `lsof -tiTCP:<port> -sTCP:LISTEN` で確かめる。使用中なら `lsof -a -p <pid> -d cwd` で持ち主を見て、この worktree のものならそのまま使う。別の場所のものなら kill せず人に聞く
3. それぞれ `run_in_background` で起動する（`N` は issue 番号）
   ```bash
   # API
   cd api/public && APP_URL=http://localhost:810N FRONTEND_URL=http://localhost:310N SANCTUM_STATEFUL_DOMAINS=localhost:310N \
   SESSION_COOKIE=next-team-issue-N-session REDIS_PREFIX=next-team-issue-N-database- CACHE_PREFIX=next-team-issue-N-cache- HORIZON_PREFIX=next-team-issue-N-horizon: \
   php -S localhost:810N ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
   # web
   cd web && API_URL=http://localhost:810N NEXT_PUBLIC_API_URL=http://localhost:810N WEB_ORIGIN=http://localhost:310N npm run dev -- --port 310N
   ```
   （`810N` / `310N` は `8100 + N` / `3100 + N` の値に置き換える。issue #12 なら 8112 / 3112）
4. `http://localhost:<API>/up` と `http://localhost:<web>/login` が 200 を返すまで待つ（最大 60 秒。超えたらログの末尾を報告して止まる）
5. `orca tab list --json` を見て、`localhost:3002` などこのブランチ以外の画面のタブがあれば `orca tab switch` → `orca goto --url http://localhost:<web>/login` で差し替える。なければ `orca tab create --url http://localhost:<web>/login --json`
6. 報告に「画面: http://localhost:<web>（`issue/<番号>` の worktree。admin@example.com / password）」と、止めるときは起動したバックグラウンドのプロセスを止めることを書く。開発のサーバー（8002 / 3002）には触らない
   - 赤: `gh run view <run id> --log-failed` で失敗を読み、手元の `make verify` との差（PHP / Node の版、依存、環境変数、Redis の有無）を調べて直し、push し直して 6 を繰り返す。「再実行したら緑」は合格にしない。2 回直しても赤なら issue に `requires-human` ラベルを付けて理由をコメントし、人に報告する

## 禁止
- 赤のまま PR を作る、CI が赤のまま「完了」と報告する
- `main` に直接 push する、自分でマージする
