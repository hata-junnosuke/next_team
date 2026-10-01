# Git 運用（常時適用）

- リポジトリは `next_team/` 直下の 1 つ（`api/` と `web/` をまとめて管理する）
- コミットは小さく: タスクごと。メッセージは日本語で `feat(api): F-05 募集の作成・編集` / `feat(web): 募集一覧画面` / `fix:` / `docs:` / `chore:` / `test:`
- コミット前に `/verify pre-commit` を通す。赤のままコミットしない
- `git push --force` `git reset --hard` `git clean -f` は禁止（PreToolUse フックが止める）
- ブランチ: エピックは `epic/<名前>`、並列担当は `task/<担当>-<内容>`。`main` へのマージは人が行う
- `.env` `composer.lock` `package-lock.json` `vendor/` `node_modules/` `storage/` `.next/` は編集しない（フックが止める）
- コミットメッセージ末尾に、コミットするときに実際に動いているリードのモデル名で `Co-Authored-By: Claude <モデル名> <noreply@anthropic.com>` を付ける（例: `Claude Opus 5.5`、`Claude Fable 5.1`）。`/model` で切り替えたら、それ以降のコミットは新しいモデル名にする
