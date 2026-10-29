---
name: code-review
description: 多軸レビュー。spec-reviewer / code-reviewer / security-reviewer を並列に起動して結果を 重大・推奨・任意 に統合する。issue-flow のレビュー工程、またはコミット前の独立レビューで使う
argument-hint: [対象パスまたはタスクID]（省略時は git diff の全体）
---

# /code-review — 多軸レビュー

対象: **$ARGUMENTS**

## 変更の概要（自動取得）
!`cd "$CLAUDE_PROJECT_DIR" && git diff --stat HEAD 2>/dev/null | tail -20`

## 手順
1. 対象の F-xx と変更ファイル一覧を確定する。api / web / mobile のどれか（または複数）かを書く
2. **同時に** 3 つのサブエージェントを起動する（それぞれ読み取り専用）:
   - `spec-reviewer`: 要件との整合・影響範囲・api と web・mobile の境界
   - `code-reviewer`: api / web / mobile の実装品質（mobile なら `mobile/CLAUDE.md` と docs/01 の 7A.4 を読むよう渡す）
   - `security-reviewer`: テナント分離・認証・署名付きURL・Server Action・mobile のトークンの扱い
   - **mobile だけの変更**は `spec-reviewer` ∥ `code-reviewer` の 2 体でよい（トークンの扱いを変えたときだけ `security-reviewer` も。`.claude/rules/common/agents.md`）
3. テーブル・集計・トランザクション・非同期のテーブルに触れていれば `database-reviewer` も加える（**同時に 4 体まで**）
4. 表を 1 つに統合し、重複を除く。重大度は最も高いものを採用
5. 出力:

| 重大度 | 担当レビュアー | ファイル:行 | 指摘 | 修正の方向 |
|---|---|---|---|---|

6. `重大` があれば「implementer に差し戻し」（mobile はリードが書くので「リードが直す」）、なければ「レビュー通過」と明記する。修正はこのスキルでは行わない

## 基準
- 確信 80% 以上の指摘だけ載せる（ノイズを減らす）
- 「動いているから良い」ではなく「要件・契約・規約に照らして正しいか」で判定する
