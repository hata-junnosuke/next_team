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
   - 緑: Orca のブラウザで開いてから、PR の URL と CI の結果を報告して止まる。**マージは人が行う**
     - `orca tab create --url <PR の URL> --json`（今の worktree のタブに開く）
     - `web/` に変更があれば、`orca tab create --url http://localhost:3002 --json` も開く（人が画面の確認項目を見るため。3002 が別の場所のプロセスなら `make ports` の結果を報告に添える）
     - `orca` が動かない・Orca が起動していないときは、エラーをそのまま報告に書いて先に進む（PR の作成は止めない）
   - 赤: `gh run view <run id> --log-failed` で失敗を読み、手元の `make verify` との差（PHP / Node の版、依存、環境変数、Redis の有無）を調べて直し、push し直して 6 を繰り返す。「再実行したら緑」は合格にしない。2 回直しても赤なら issue に `requires-human` ラベルを付けて理由をコメントし、人に報告する

## 禁止
- 赤のまま PR を作る、CI が赤のまま「完了」と報告する
- `main` に直接 push する、自分でマージする
