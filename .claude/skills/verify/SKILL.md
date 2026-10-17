---
name: verify
description: 検証コマンド。quick（変更したスタックの整形と変更分のテスト）/ pre-commit（+ 全テスト + 型 + ESLint + security-reviewer）/ full（+ 本番ビルド + ルート一覧 + 画面の確認項目の整理）の 3 段階。コミット前・エピック完了時・Stop フックに差し戻されたときに使う
argument-hint: [quick|pre-commit|full]（省略時 quick）
allowed-tools: Bash(make:*), Bash(git diff:*), Bash(git status:*)
---

# /verify — 検証（$ARGUMENTS）

## 現在の状態（自動取得）
- 変更ファイル: !`cd "$CLAUDE_PROJECT_DIR" && git status --short | head -30`
- api Pint: !`cd "$CLAUDE_PROJECT_DIR" && make -s api-pint-test 2>&1 | tail -5`
- api PHPUnit: !`cd "$CLAUDE_PROJECT_DIR" && make -s api-test 2>&1 | tail -15`

## モード別の手順

### quick（既定）
1. 変更ファイルが `api/` にあれば: Pint が赤なら `make api-pint`、対応するテストクラスを `make api-test FILTER=<クラス名>`
2. 変更ファイルが `web/` にあれば: `make web-typecheck` と `make web-lint`
3. 結果を「api: Pint 緑/赤、テスト N 件 緑/赤（失敗名）／ web: 型 緑/赤、ESLint 緑/赤」の 1 行で報告

### pre-commit
1. `make verify`（api の Pint と全テスト、web の型と ESLint をすべて）
2. `security-reviewer` を起動し、`重大` がないことを確認
3. 全部緑なら「コミット可」と報告。赤なら失敗一覧を `build-error-resolver` に渡す

### full
1. pre-commit を実行
2. `make verify-full`（+ Prettier のチェックと `next build`）
3. `make api-routes` を出し、`docs/01` の API 契約のエンドポイントと一致すること、`register` / `password.*` / `two-factor.*` が **出ない** こと（Fortify の features が空）を確認
4. issue の完了条件にある画面の確認項目を並べ、テストで担保されている項目はテスト名を、人の目視が必要な項目は「要手動確認」を書く
5. `docs/01` の必須のテストファイル 6 本がすべて存在し緑であることを明記
6. 総括: 「画面の確認項目: テストで担保 N / 要手動確認 M」「テスト N 件緑」「型・ESLint・ビルド 緑」「重大指摘 0」

## Stop フックとの関係
このスキルは人が任意に呼ぶ検証。セッション終了時には Stop フック（`.claude/hooks/verify-loop.sh`）が api の Pint + PHPUnit と、web に変更があれば tsc + ESLint を **強制的に** 回し、赤なら差し戻す。つまり「/verify を忘れても終われない」。`next build` だけは遅いので Stop フックでは回さず、full でだけ回す。差し戻された場合は reason の失敗一覧を `build-error-resolver` に渡し、直してから再度終了する。5 回で打ち切られたら人に報告する。
