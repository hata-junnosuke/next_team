# myteam 第3弾 `next_team` — Next.js + Laravel API + 非同期処理 × ループエンジニアリング

企業側管理画面（拠点・ワーカー・募集・応募/確定・勤怠/報酬・月次集計・通知）を、`api/`（Laravel 13 の JSON API）と `web/`（Next.js 16）の 2 スタックで実装する。主題は **Job / Queue を中心とした非同期処理**。

- 要件: `../00_要件定義.md`
- 決定の経緯とフェーズ: `docs/00_計画と決定事項.md`
- 契約（テーブル・API・画面）: `docs/01_技術仕様_next.md`
- 非同期の設計: `docs/03_非同期処理の設計.md`
- ループの仕組みとゲート確認: `docs/04_ループエンジニアリング.md`

**このセッションは必ず `next_team/` で起動する。** 親フォルダで起動するとフックが一切効かない（第2弾で実際に起きた）。

## 絶対ルール
1. 業務ルールは `api/` のサービスに置く。コントローラは薄く、検証は FormRequest。**`web/` に業務ルールと検証を二重に書かない**
2. `status` はサービス経由（`transitionTo()`）でしか変えない。企業の判定は `CurrentCompany::id()` だけ
3. `api/` は JSON しか返さない。`web/` は `lib/api.ts` を通さずに `fetch` しない。レスポンスは zod で `parse` する
4. api と web の境界は `docs/01` の 6 章だけ。web 担当は `api/` のコードを読まない。形がずれていたら web で吸収せず報告する
5. 第1弾・第2弾のコードを読まない（README だけ可）。パッケージを勝手に足さない
6. `.env`・`vendor/`・`node_modules/`・ロック後のマイグレーション・フック・`.claude/.phase` は触らない（フックが止める）
7. 赤のまま終わらない（Stop フックが差し戻す。5 回で打ち切り → 人に報告）
8. 実装前に `/plan`。計画の承認・フェーズの移行は人が行う。リードが代行しない
9. サブエージェントの「できました」は判定材料にしない。検証結果とレビュー表で判定する
10. `web/` を書く前に `web/node_modules/next/dist/docs/` の該当ページを読む（Next 16 は学習データと違う）

## 現在地の見方
- フェーズ: `.claude/.phase`（0 ハーネス・ゲート / 1 API 骨格 / 2 ドメイン / 3 フロント / 4 非同期 / 5 仕上げ / 6 Could）。**人が書き換える**
- タスク状態: `.claude/state/STATE.md`。差し戻し記録: `.claude/state/loop-log.md`。教訓: `.claude/state/learnings.md`
- 起動時に SessionStart フックがこれらを要約して出す

## スキル（`/名前` で起動）
`/epic-flow` アウターループ（人が起動）/ `/issue-flow` インナーループ / `/plan` / `/verify [quick|pre-commit|full]` / `/code-review` / `/learn`。
知識スキル: `tdd-workflow` `myteam-domain-rules` `laravel-api-standards` `next-coding-standards` `async-rules` `myteam-acceptance`

## エージェント（`.claude/agents/`）
planner, architect, implementer-api, implementer-web, tester, spec-reviewer, code-reviewer, security-reviewer, database-reviewer, build-error-resolver, doc-updater。使い分けは `.claude/rules/common/agents.md`。モデルは **計画段階（planner・architect）が `fable`、それ以外は `opus`**。リード（このセッション）は `fable` で進め、利用上限に達したら `/model` で `opus` に切り替える。**同時に実装 3 体・レビュー 4 体まで。issue-flow の工程は直列**

## よく使うコマンド（`make help` で一覧）
```bash
make verify                       # Stop フック相当（api: Pint + 全テスト / web: 型 + ESLint）
make api-test FILTER=Foo          # 部分テスト
make api-pint                     # 整形
make web-typecheck && make web-lint
make fresh                        # DB 作り直し（admin@example.com / password）
make up                           # redis / mailpit
make api-serve                    # http://localhost:8002
make web-dev                      # http://localhost:3002
make horizon                      # キューワーカー（P4 から）
make ports                        # 8002 / 3002 を誰が使っているか
```

## この人と働くときの約束
- 回答・コメント・ドキュメント・コミットメッセージは日本語
- 読者は **Ruby on Rails の経験者**。Laravel の機能を説明するときは Rails の対応概念を一言添える
- 解説には日本語ドキュメントの URL を付ける: Laravel は `https://readouble.com/laravel/13.x/ja/<ページ>.html`、PHP は `https://www.php.net/manual/ja/`
- 認証など Laravel 公式パッケージがあるものは公式を使う。技術の選択で迷ったら勝手に決めず、各案のメリット・デメリットを表にして質問する
- 学習が目的。動くだけでなく「なぜそう書くか」を docs に残す
