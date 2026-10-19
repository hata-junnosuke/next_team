---
name: worktree-clean
description: PR がマージされた issue の worktree（ブランチ issue/<番号>）を片付ける。main（司令塔）で使う。引数なしならマージ済みのものを全部、番号を渡せばその 1 件。未コミットの変更がある worktree と未マージのブランチは消さない。「片付けて」「worktree を消して」で使う。SessionStart に片付け候補が出たときにも使う
argument-hint: "[issue 番号]"
---

# /worktree-clean — マージ済みの worktree を片付ける

対象: **$ARGUMENTS**（空ならマージ済みの `issue/*` の worktree を全部）

役目は**片付けだけ**。作るのは `/issue-start`。main で起動すると SessionStart フックが片付け候補を出すが、フックは消さない。消すのはこのスキルを人が打ったときだけ。

## 前提
- このスキルは **main の作業ツリー（司令塔）** で使う。今のブランチが `main` でなければ止める
- 対象は `issue/<番号>` のブランチの worktree だけ。レビュー用（ブランチ `review`）や main は触らない
- `git worktree remove --force` / `git branch -D` / `git reset --hard` / `git clean -f` は使わない。消せなければ止めて人に聞く
- 別の作業ツリーの中で操作するときは `cd` しない。`git -C <場所>` を使う

## 手順

### 1. 候補を集める
```bash
git branch --show-current                           # main でなければ止める
git worktree list --porcelain                       # worktree <場所> と branch refs/heads/issue/<番号> の組
gh pr list --state merged --limit 100 --json number,headRefName
```
- 引数があれば `issue/<番号>` の 1 件だけ。その worktree がなければ、そう報告して止める
- `headRefName` が一致するマージ済みの PR がない worktree は対象外（未マージ。報告だけする）

### 2. 1 件ずつ確かめて片付ける
| 確かめること | だめなとき |
|---|---|
| PR がマージ済み（1 で確認済み） | 飛ばして「未マージ」と報告 |
| `git -C <WT> status --short` が空 | 飛ばして「未コミットあり」と変更の一覧を報告（勝手に捨てない） |
| `git -C <WT> log origin/main..HEAD --oneline` が空（マージ後に足したコミットがない） | 飛ばして、そのコミットを報告して人に聞く |

すべて満たしたら:
```bash
git worktree remove <WT>
git branch -d <BRANCH>        # 消せなければ未マージのコミットがある。-D にせず人に聞く
```

### 3. main を最新にする（最後に 1 回）
```bash
git fetch --prune origin      # マージ後にリモートで自動削除された issue/* の追跡ブランチを消す
git pull --ff-only
```
`git branch -d` が「not fully merged」で失敗したときは、3 の後にもう一度だけ試す（`main` が古くてマージコミットを持っていなかっただけのことがある）。

### 4. 報告
- 片付けたもの: worktree の場所・ブランチ・PR 番号
- 残したもの: 場所と理由（未マージ / 未コミットあり / マージ後のコミットあり / `-d` で消せない）
- `main` の今のコミット（`git log -1 --oneline`）
- Orca などのツールの画面に消した worktree が残っていれば、そちらで閉じてもらう
