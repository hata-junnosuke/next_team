---
name: issue-start
description: 計画が承認された issue の作業場所を作る。main（司令塔）から issue 用の worktree（ブランチ issue/<番号>）を切り、依存・.env の確認・DB まで整える。Orca を使用していれば、その worktree の画面（issue ごとのポート）と Claude（最初の指示 /epic-flow か /issue-flow つき）も開く。「issue #◯ を始めて」「#◯ の worktree を作って」で使う
argument-hint: <issue 番号>
---

# /issue-start — issue の作業場所を作る

対象: **issue #$ARGUMENTS**（番号がなければ `gh issue list --state open` を見せて聞く）

役目は**作業場所づくりと、そこで作業を始めさせるところまで**。このセッション（main）では実装・計画・片付けはしない（片付けは `/worktree-clean`）。Orca を使用していれば、作った worktree の画面と Claude を開き、その Claude に `/epic-flow` か `/issue-flow` を最初の指示として渡す。Orca の外なら、人が worktree で Claude を起動してそれを打つ。

## 前提
- このスキルは **main の作業ツリー（司令塔）** で使う。main ではコードを書かない（`.claude/rules/common/git-workflow.md`）
- 1 issue = 1 ブランチ `issue/<番号>` = 1 worktree。置き場所は人が指定したフォルダ。指定がなければ、すでにある worktree（`git worktree list` の 2 行目以降）と同じ親フォルダの `issue-<番号>`。どちらもなければ人に聞く。名前は付けない（中身は issue のタイトルで分かる）
- `.env` / `.env.local` は Claude が読まない・書かない。コピーは `make worktree-env` に任せる（中身を出さない）
- 画面のサーバーは、Orca を使用している場合だけ、作った worktree の Orca のターミナルで issue ごとのポート（API `8100 + 番号` / web `3100 + 番号`）に起動する（`.claude/skills/issue-flow/dev-server.md`）。main の 8002 / 3002 と `/pr-env` の 8012 / 3012 には触らない
- Orca を使用しているかは、環境変数 `ORCA_WORKTREE_ID` が空でないかで判定する。CLI は `ORCA_CLI_COMMAND` があればその値、なければ `orca`
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
- `BRANCH=issue/<番号>`、`WT=<置き場所>/issue-<番号>`（前提の「置き場所」）
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
main の作業ツリーに未コミットの変更があっても、worktree は `origin/main` から切るので持ち込まない。逆に「main にまだ push していない変更（例: issue の計画に合わせて直した契約の `docs/01`）」があるなら、先に人に push / PR してもらうか聞く。

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

### 7. Orca で画面と Claude を開く（Orca を使用している場合だけ）
`ORCA_WORKTREE_ID` が空なら飛ばして 8 へ。`orca` がエラーになったら、エラーを報告に書いて先に進む（`runtime_access_denied` ならサンドボックスの外で再実行する。`orca open` や Orca の再起動はしない）。

1. Orca が worktree を認識しているか確かめる: `orca worktree list --json` に `<WT>` があること（`git worktree add` で作ったものも自動で載る。なければ数秒おいてもう一度。それでもなければ 7 を飛ばして 8 の「Orca の外」の案内にする）
2. 画面を起動して、その worktree のブラウザに開く: `.claude/skills/issue-flow/dev-server.md` の手順を `<WT>` と issue 番号で行う
3. 最初の指示を決める: 1 で見た issue 本文の未チェックのタスク（`- [ ] **`）が 2 件以上なら `/epic-flow <番号>`、それ以外（1 件、またはチェックリストのない小さな issue）なら `/issue-flow <番号>`
4. その worktree で Claude を起動する（リードのモデルは `fable`。CLAUDE.md）
   ```bash
   orca agent launch --agent claude --worktree path:<WT> --model claude-fable-5-1 --prompt "<3 の指示>" --focus --json
   ```
   - モデルの指定でエラーになったら、`--model` を外してもう一度（起動した Claude で人が `/model` を切り替える）
   - 結果の `outcome.kind`（chat / terminal）と `receipt.detail` を報告に書く
   - Claude は worktree の直下で起動するので、その worktree のフックが効く（SessionStart に issue 番号が出る）

### 8. 報告
- issue の番号・タイトル、`BRANCH`、`WT`、元にした main のコミット
- 実行した準備（composer / npm / `.env` のコピー / fresh）と、人の作業が残っていればそれ（`DB_DATABASE` の修正など）
- **Orca を使用している場合**: 画面の URL（`http://localhost:<3100 + 番号>`、admin@example.com / password）、API・web のターミナル、起動した Claude と渡した指示。計画の承認やマージなど人の判断が要るところでは、その Claude が止まって聞く
- **Orca の外の場合**: 次の一手（この順で人が行う）
  1. `WT` を開いて Claude を起動する（`cd <WT> && claude`、またはエディタや worktree を扱う道具で `WT` を開く）。**worktree の直下で起動する**（フックが効く。SessionStart に今の issue 番号が出る）
  2. タスクが複数なら `/epic-flow <番号>`、1 件なら `/issue-flow <番号>`
- このセッション（main の司令塔）は続けて使える
