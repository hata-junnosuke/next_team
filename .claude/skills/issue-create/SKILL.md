---
name: issue-create
description: GitHub issue を .github/ISSUE_TEMPLATE/ のテンプレート（task / bug）どおりに作る。見出しを変えずに埋めて gh issue create する。/plan・/epic-flow で issue がないときと、不具合を見つけたときに使う
argument-hint: <task|bug> <タイトルまたは概要>
---

# /issue-create — issue を作る

対象: **$ARGUMENTS**

issue の **形** は `.github/ISSUE_TEMPLATE/` が決め、このスキルは **手順** だけを決める。見出しを足したり消したり並べ替えたりしない（出力を安定させるため）。

## 手順
1. 種類を決める: 仕事なら `task`、期待と違う動きなら `bug`。引数で決まっていなければ内容から判断する
2. 重複を確かめる: `gh issue list --state open --search "<キーワード>"`。同じものがあれば作らずにその番号を返す
3. テンプレートを読む: `.github/ISSUE_TEMPLATE/<種類>.md`。先頭の `---` で囲まれた部分（frontmatter）から `title` の接頭辞と `labels` を取り、本文は frontmatter を除いた残り
4. 本文を埋める（スクラッチパッドに一時ファイルとして書く）
   - 見出し（`## ...`）はテンプレートのまま全部残す。書くことがない見出しは「なし」と書く
   - `<!-- -->` の案内コメントは消す
   - task の「タスク」は空のまま（`- [ ] **T1** ` の行も消してよい）。分解は `/plan` の planner が行い、人が承認する
   - task の「決定事項」は会話で人が決めたことだけ書く。リードが決めたことにしない
   - bug の「完了条件」には再現するテスト名を書く（api）。`.env` の値・個人情報・Cookie は書かない
5. タイトル: テンプレートの接頭辞（`feat(api|web): ` / `fix(api|web): `）の `api|web` を実際の担当（`api` / `web`、両方なら外す。ハーネスや docs なら `chore:` / `docs:`）にして、日本語で要点を書く
6. 作る: `gh issue create --title "<タイトル>" --body-file <一時ファイル> [--label <ラベル>]`
7. 作った issue の URL と番号を報告する。次の手順は `/plan <番号>`

## 禁止
- テンプレートにない見出しを足す。追加の情報は該当する見出しの中に書く
- 人の承認なしに「（人が承認）」と書く
