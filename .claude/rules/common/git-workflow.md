# Git 運用（常時適用）

- リポジトリは `next_team/` 直下の 1 つ（`api/` と `web/` をまとめて管理する）
- コミットは小さく: タスクごと。メッセージは日本語で `feat(api): F-05 募集の作成・編集` / `feat(web): 募集一覧画面` / `fix:` / `docs:` / `chore:` / `test:`
- コミット前に `/verify pre-commit` を通す。赤のままコミットしない
- `.claude/`（フック・スキル・規約・`settings.json`）の変更も、PR の前に `/code-review` を通して重大 0 件にする。「文面だけの変更」を理由に省かない（`/pr-create` の手順 2）
- `git push --force` `git reset --hard` `git clean -f` は禁止（PreToolUse フックが止める）。フック（`guard-bash.sh`）の目的は**うっかりの防止**で、わざと迂回する書き方までは止めない。main の最後の守りは GitHub のブランチ保護（PR 必須・管理者にも適用・強制 push と削除の禁止）。フックを迂回する書き方を探したり使ったりしない
- issue・PR・コミットの本文は Write ツールでファイルに書き、`--body-file` / `-F <ファイル>` で渡す。`guard-bash.sh` はヒアドキュメントや引用符の中の文字も判定するので、本文に `git push --force` などと書くと止まる（本文を判定から外す仕組みは抜け道が続いたのでやめた。issue #23）
- ブランチ: 1 issue = 1 ブランチ `issue/<番号>`（例: `issue/3`）。PR の本文に `Closes #<番号>`。`main` へのマージは人が行う
- issue を並列に進めるときは issue ごとに worktree を分ける。main の作業ツリーは issue の作成・計画・マージの確認に使う。worktree は `/issue-start <番号>` で作り、PR がマージされたら main で `/dev-refresh` で片付ける（main で起動すると SessionStart フックが片付け候補を出す）。main の取り込み（`git merge origin/main`）は各 worktree の Claude が、タスクの始めと PR を作る前に行う（`.claude/skills/issue-flow/sync-main.md`）。main のセッションは他の worktree に merge しない。`/epic-flow`・`/issue-flow`・`/pr-create` は worktree の中でしか使えない（main の作業ツリーでは UserPromptSubmit フック `guard-prompt.sh` が止める。判定はブランチ名ではなく場所）
- `.env` `composer.lock` `package-lock.json` `vendor/` `node_modules/` `storage/` `.next/` は編集しない（フックが止める）
- コミットメッセージ末尾に、コミットするときに実際に動いているリードのモデル名で `Co-Authored-By: Claude <モデル名> <noreply@anthropic.com>` を付ける（例: `Claude Opus 5.5`、`Claude Fable 5.1`）。`/model` で切り替えたら、それ以降のコミットは新しいモデル名にする
