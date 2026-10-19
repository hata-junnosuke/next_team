# この worktree に main を取り込む

`/issue-flow`（手順 0。タスクを始めるたび）と `/pr-create`（手順 1。PR を作る前）から使う手順。人に「main を取り込んで」と頼まれたときも使う。**この worktree の Claude（リード）が自分のブランチにだけ行う。** main のセッションは他の worktree に merge しない（`/dev-tidy` は遅れと衝突の見込みを報告するだけ）。

取り込みは `git merge`。rebase は使わない（push 済みのブランチの rebase は force push が要り、フックで禁止）。

## 手順
1. 遅れを確かめる
   ```bash
   git fetch origin main
   git rev-list --count HEAD..origin/main
   ```
   0 なら何もしないで戻る
2. 作業ツリーを確かめる: `git status --short` が空でなければ、今の作業を先にコミットする（`/verify pre-commit` を通してから）。コミットできる状態でなければ、取り込まずに戻り、人に伝える。`git stash` で逃がさない
3. 取り込む前のコミットを控える（`PREV=$(git rev-parse HEAD)`）
4. 取り込む
   ```bash
   git merge --no-edit origin/main
   ```
   - **衝突したら**、`git diff --name-only --diff-filter=U` で衝突したファイルを控えてから `git merge --abort` で元に戻し、止まって報告する。勝手に解かない（どちらの変更を残すかは人が決める）。人に解き方を指示されたら、その指示どおりに解いてコミットする
5. 取り込んだ変更に合わせて整える（`git diff --name-only $PREV HEAD` で判定）
   | 変わったもの | やること |
   |---|---|
   | `api/composer.lock` | `composer install -d api --no-interaction` |
   | `web/package-lock.json` | `npm ci --prefix web` |
   | `api/database/` 以下 | `make fresh`（この worktree の DB を作り直す。画面で作ったデータは消える） |
   | `.claude/` 以下 | スキルやフックが変わった。読み込み済みのスキルは古いままなので、変わったスキルを使う前に読み直す |
6. `make verify` を実行する。赤なら `build-error-resolver` に渡す（取り込みで壊れたのか、この issue の変更と噛み合っていないのかを報告に書く）
7. 緑なら push する（`origin` の `issue/<番号>`）。まだ一度も push していないブランチなら、ここでは push しない（`/pr-create` が push する）。`/pr-create` の中から呼ばれたときも、ここでは push せず `/pr-create` の手順 5 に任せる

## 報告
- 取り込んだ main のコミット（`git log -1 --oneline origin/main`）と、取り込んだコミット数
- 整えたもの（install / fresh / 読み直したスキル）
- 衝突して止まったときは、衝突したファイルの一覧

## 注意
- 画面のサーバー（`dev-server.md`）は起動し直さなくてよい。`next dev` は変更をそのまま読み、`php -S` はリクエストごとに読み直す
