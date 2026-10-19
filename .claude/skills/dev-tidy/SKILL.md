---
name: dev-tidy
description: 開発環境を整える。PR がマージされた issue の worktree（ブランチ issue/<番号>）を片付けて main を最新にし、残っている worktree が main より何コミット遅れているかと衝突の見込みを報告する（残っている worktree には触らない）。main（司令塔）で使う。引数なしならマージ済みのものを全部、番号を渡せばその 1 件を片付ける。「片付けて」「整えて」「worktree を消して」で使う。SessionStart に片付け候補が出たときにも使う
argument-hint: "[issue 番号]"
---

# /dev-tidy — 開発環境を整える

対象: **$ARGUMENTS**（空ならマージ済みの `issue/*` の worktree を全部）

役目は 2 つ。
1. マージ済みの worktree を片付けて、main を最新にする（手順 1〜3）
2. 残っている `issue/*` の worktree が main より遅れていないか、取り込むと衝突しそうかを**報告するだけ**（手順 4）。取り込みはその worktree の Claude が行う（`.claude/skills/issue-flow/sync-main.md`。issue-flow の始めと pr-create の前）。このセッションから他の worktree に merge しない（その worktree で作業中の Claude が読んだ後でファイルが書き換わるため。`.claude/rules/common/agents.md` の「同じファイルを触る担当は同時に 1 体」）

作るのは `/issue-start`。main で起動すると SessionStart フックが片付け候補を出すが、フックは消さない。消すのはこのスキルを人が打ったときだけ。

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

すべて満たしたら、まず画面のサーバー（API `8100 + 番号` / web `3100 + 番号`）が動いていれば止める（`.claude/skills/issue-flow/dev-server.md` の「止める」。場所が `<WT>` の中のものだけ）。次に worktree を消す。

- **Orca を使用している場合**（環境変数 `ORCA_WORKTREE_ID` が空でない）: Orca から消す。その worktree のターミナル（起動した Claude）も閉じる。CLI は `ORCA_CLI_COMMAND` があればその値、なければ `orca`
  ```bash
  orca worktree rm --worktree path:<WT> --json    # --force は付けない
  ```
  `ok: true` が返っても、削除はすぐには終わらない。少しのあいだフォルダと `git worktree list` の行が残る。`git worktree list` から `<WT>` が消えるまで待つ（2 秒おきに最大 20 秒）。消える前に `git branch -d` を打つと「checked out at」で失敗する。20 秒たっても残っていれば報告して人に聞く
  `orca` がエラーになったら、エラーを報告に書いて、下の「Orca の外」の手順で消す。そのときはターミナルが残るので、Orca でその worktree を閉じてもらう
- **Orca の外**:
  ```bash
  git worktree remove <WT>
  ```

どちらの後も、ブランチを消す。
```bash
git branch -d <BRANCH>        # 消せなければ未マージのコミットがある。-D にせず人に聞く
```
`orca worktree rm` は、マージ済みのブランチも一緒に消すことがある。`git branch -d` が `branch '<BRANCH>' not found` になったら、もう消えているので成功として扱う
最後に、画面のポート（`8100 + 番号` / `3100 + 番号`）が空いたか `lsof -tiTCP:<port> -sTCP:LISTEN` で確かめる。残っていれば kill せず、持ち主（`lsof -a -p <pid> -d cwd`）を報告する

### 3. main を最新にする（最後に 1 回）
```bash
git fetch --prune origin      # マージ後にリモートで自動削除された issue/* の追跡ブランチを消す
git pull --ff-only
```
`git branch -d` が「not fully merged」で失敗したときは、3 の後にもう一度だけ試す（`main` が古くてマージコミットを持っていなかっただけのことがある）。

### 4. 残っている worktree の遅れと衝突の見込み（報告だけ）
片付けなかった `issue/*` の worktree（未マージ・飛ばしたもの）ごとに、3 で最新にした `origin/main` と比べる。worktree の中のファイルもブランチも変えない。
```bash
git rev-list --count <BRANCH>..origin/main                       # main より何コミット遅れているか
git merge-tree --write-tree --name-only origin/main <BRANCH>     # 終了コード 0 = 衝突なし、1 = 衝突あり。1 行目は tree の id、2 行目から空行までが衝突するファイル
```
- 遅れが 0 なら「最新」
- 遅れがあり衝突しないなら「N コミット遅れ（衝突なし）」
- 衝突するなら「N コミット遅れ（衝突: ファイル名）」。衝突の解き方はその worktree の Claude か人が決める
- 取り込み（`sync-main.md`）は、その worktree の Claude が次のタスクの始めか PR を作る前に行う。すぐ取り込みたければ、その worktree の Claude に「main を取り込んで」と頼んでもらう

### 5. 報告
- 片付けたもの: worktree の場所・ブランチ・PR 番号
- 残したもの: 場所と理由（未マージ / 未コミットあり / マージ後のコミットあり / `-d` で消せない）
- `main` の今のコミット（`git log -1 --oneline`）
- 残っている worktree ごとの遅れと衝突の見込み（4 の結果）
- Orca の外の手順で消した worktree が Orca の画面に残っていれば、そちらで閉じてもらう
