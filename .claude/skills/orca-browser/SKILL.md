---
name: orca-browser
description: URL か手元のファイル（図解の HTML など）をブラウザで開く。Orca の中なら今の worktree の内蔵ブラウザのタブで開き（同じ URL のタブがあれば開き直す）、Orca の外なら open で開く。開けなくても作業は止めない。「ブラウザで開いて」「図解を開いて」と言われたときと、作業の途中で人に見せたいページがあるときに使う
argument-hint: <URL か ファイルのパス>
---

# /orca-browser — ブラウザで開く

対象: **$ARGUMENTS**

開くだけのスキル。ほかのスキル（`dev-server.md`・`/pr-create`・`/pr-env` など）に書いてある Orca の手順は、そのスキルのとおりに行う。このスキルで置き換えない。Orca の CLI の使い方の詳細は `orca-cli` スキルに任せる。

## 手順
1. **開くものを URL にする**
   - `http://` `https://` で始まれば、そのまま使う
   - ファイルのパスなら、絶対パスにしてファイルがあることを確かめる（なければ開かずに報告する）。URL は `file://<絶対パス>`（空白は `%20` にする）
2. **Orca の外**（環境変数 `ORCA_WORKTREE_ID` が空）: `open <URL>` で既定のブラウザに開いて終わり
3. **Orca の中**: CLI は `ORCA_CLI_COMMAND` があればその値、なければ `orca`。`<WT>` は `git rev-parse --show-toplevel`
   ```bash
   orca tab list --worktree path:<WT> --json     # .result.tabs[] の url と browserPageId
   ```
   - **同じ URL のタブがある**: そのタブを前に出して読み込み直す（新しいタブを作らない）
     ```bash
     orca tab switch --page <browserPageId> --worktree path:<WT> --json
     orca reload --page <browserPageId> --worktree path:<WT> --json
     ```
   - **ない**: 新しいタブで開く
     ```bash
     orca tab create --worktree path:<WT> --url <URL> --json
     ```
4. **開けなかったとき**: `orca` が `runtime_access_denied` を返したら、サンドボックスの外で同じコマンドをもう一度だけ実行する。ほかのエラーなら、エラーをそのまま報告に書き、`open` は使わずに URL かパスを人に伝えて作業を続ける（開けないことを理由に止まらない）
5. 人に伝える: 開いた URL（ファイルなら絶対パスも）と、新しいタブか開き直したタブか

## 注意
- 開くのは今の worktree のタブだけ。別の worktree のタブは触らない
- 読むだけでなく画面を操作する（クリック・入力・スクリーンショット）ときは `orca-cli` スキルの手順に従う
