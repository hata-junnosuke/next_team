# Git 運用（常時適用）

- リポジトリは `next_team/` 直下の 1 つ（`api/` と `web/` をまとめて管理する）
- コミットは小さく: タスクごと。メッセージは日本語で `feat(api): F-05 募集の作成・編集` / `feat(web): 募集一覧画面` / `fix:` / `docs:` / `chore:` / `test:`
- コミット前に `/verify pre-commit` を通す。赤のままコミットしない
- `git push --force` `git reset --hard` `git clean -f` は禁止（PreToolUse フックが止める）
- ブランチ: 1 issue = 1 ブランチ `issue/<番号>-<名前>`（例: `issue/3-design-refresh`）。PR の本文に `Closes #<番号>`。`main` へのマージは人が行う（P1〜P5 の `epic/<名前>` `task/<担当>-<内容>` は過去の名前）
- issue を並列に進めるときは issue ごとに worktree を分ける。main の作業ツリーは issue の作成・計画・マージの確認に使う。worktree は `/issue-start <番号>` で作る（片付けも同じスキル）
- `.env` `composer.lock` `package-lock.json` `vendor/` `node_modules/` `storage/` `.next/` は編集しない（フックが止める）
- コミットメッセージ末尾に、コミットするときに実際に動いているリードのモデル名で `Co-Authored-By: Claude <モデル名> <noreply@anthropic.com>` を付ける（例: `Claude Opus 5.5`、`Claude Fable 5.1`）。`/model` で切り替えたら、それ以降のコミットは新しいモデル名にする
