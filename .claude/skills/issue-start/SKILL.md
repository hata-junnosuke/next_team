---
name: issue-start
description: 計画が承認された issue の作業場所を作る。main（司令塔）から issue 用の worktree（ブランチ issue/<番号>）を切り、依存・.env の確認・DB まで整えて、そこで Claude を起動すれば /epic-flow か /issue-flow に入れる状態にする。「issue #◯ を始めて」「#◯ の worktree を作って」で使う
argument-hint: <issue 番号>
---

# /issue-start — issue の作業場所を作る

対象: **issue #$ARGUMENTS**（番号がなければ `gh issue list --state open` を見せて聞く）

役目は**作業場所づくりだけ**。実装・計画はしない。作った worktree で人が Claude を起動し直し、`/epic-flow` か `/issue-flow` を打つ。

## 前提
- このスキルは **main の作業ツリー（司令塔）** で使う。main ではコードを書かない（`.claude/rules/common/git-workflow.md`）
- 1 issue = 1 ブランチ `issue/<番号>` = 1 worktree。置き場所は `~/orca/workspaces/next_team/issue-<番号>`。名前は付けない（中身は issue のタイトルで分かる）（Orca から開ける。レビュー用の `レビュー用` と並ぶ）
- `.env` / `.env.local` は Claude が読まない・書かない。コピーは `make worktree-env` に任せる（中身を出さない）
- サーバーは起動しない（api 8002 / web 3002 はどの worktree も同じ番号。動かすのは一度に 1 つ）
- `git reset --hard` / `git clean -f` は使わない（フックで禁止）
- 別の作業ツリーの中で操作するときは `cd` しない。`git -C <場所>`、`composer -d <場所>/api`、`npm --prefix <場所>/web`、`make -C <場所>` を使う

## 手順

### 1. issue を確かめる
```bash
gh issue view <番号> --json number,title,state,labels,body
```
| 見ること | どうするか |
|---|---|
| `state` が OPEN でない | 止めて人に聞く |
| 本文にタスクのチェックリスト（`- [ ] **ID**`）と完了条件がない、または「人が承認」の記載がない | 止めて `/plan <番号>` を案内する（loop-rules 1: 完了条件のないタスクは着手しない）。小さな issue で本文に「完了条件」があればそのまま進めてよい |
| `requires-human` ラベルが付いている | 止めて、理由のコメントを人に見せる |

### 2. 場所を決める
- `BRANCH=issue/<番号>`、`WT=~/orca/workspaces/next_team/issue-<番号>`
- すでにあるか調べる:
```bash
git worktree list
git branch --list "issue/<番号>"
git ls-remote --heads origin "issue/<番号>"
```
| 状況 | どうするか |
|---|---|
| その issue の worktree がもうある | 作らずに、その場所を報告して 4 へ（依存と DB の確認だけ行う） |
| ブランチだけある（ローカルかリモート） | 新しく切らずに、そのブランチで worktree を作る（`git worktree add <WT> <BRANCH>`。リモートだけなら `git fetch origin <BRANCH>` の後 `git worktree add --track -b <BRANCH> <WT> origin/<BRANCH>`） |
| どちらもない | 3 へ |

### 3. worktree を作る（最新の main から切る）
```bash
git fetch origin main
git worktree add -b <BRANCH> <WT> origin/main
git -C <WT> log -1 --oneline
```
main の作業ツリーに未コミットの変更があっても、worktree は `origin/main` から切るので持ち込まない。逆に「main にまだ push していない変更（例: 計画を書いた docs）」があるなら、先に人に push / PR してもらうか聞く。

### 4. 依存を入れる
```bash
test -d <WT>/api/vendor        || composer install -d <WT>/api --no-interaction
test -d <WT>/web/node_modules  || npm ci --prefix <WT>/web
```
（2 で既存の worktree を使った場合も、`composer.lock` / `package-lock.json` が main と違えば入れ直す）

### 5. `.env` をコピーする
```bash
make worktree-env WT=<WT>
```
main（`git worktree list` の先頭）の `api/.env` と `web/.env.local` を、中身を表示せずにコピーする。すでにあるファイルは上書きしない。`DB_DATABASE` が絶対パス（main の DB を共有してしまう）なら値を出さずに失敗するので、そのときは止めて人に直してもらう。Claude は `.env` を `cat` / `cp` で直接触らない（このターゲットだけが `settings.json` で許可されている）。

### 6. DB を作る
DB は SQLite の `api/database/database.sqlite`（git 管理外なので worktree には無い）。worktree ごとに別の DB になる。
```bash
test -f <WT>/api/database/database.sqlite || touch <WT>/api/database/database.sqlite
make -C <WT> fresh
```
（シードの管理者は `admin@example.com / password`）

### 7. 報告
- issue の番号・タイトル、`BRANCH`、`WT`、元にした main のコミット
- 実行した準備（composer / npm / `.env` のコピー / fresh）と、人の作業が残っていればそれ（`DB_DATABASE` の修正など）
- 次の一手（この順で人が行う）:
  1. Orca で `WT` を開く（または `cd <WT> && claude`）。**worktree の直下で起動する**（フックが効く。SessionStart に今の issue 番号が出る）
  2. タスクが複数なら `/epic-flow <番号>`、1 件なら `/issue-flow <番号>`
- このセッション（main の司令塔）は続けて使える

## 片付け（PR がマージされた後、「片付けて」と言われたら）
```bash
gh pr list --state merged --head <BRANCH>     # マージ済みを確かめる。未マージなら止めて聞く
git -C <WT> status --short                    # 空でなければ止めて人に聞く（勝手に捨てない）
git worktree remove <WT>
git branch -d <BRANCH>                        # -D は使わない。消せなければ未マージなので人に聞く
git pull --ff-only                            # main（司令塔）を最新に
```
