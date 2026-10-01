# STATE — エピックとタスクの状態（5+1 の「メモリ」）

セッションをまたいで引き継ぐ状態。`planner` が書き、`issue-flow` が更新し、`session-context.sh` が起動時に読む。

## エピック

| エピック | フェーズ | ブランチ | 状態 | 備考 |
|---|---|---|---|---|
| p0-harness | 0 | main | 進行中 | ハーネスと docs は 2026-10-01 に作成済み。残りはゲート確認（下の G-1〜G-6）。**`next_team/` で起動したセッションで行う** |
| p1-skeleton | 1 | `epic/p1-skeleton`（未作成） | 未着手 | Sanctum + Fortify、エラー形式、拠点（F-01）を API → 画面まで縦に 1 本 |
| p2-domain | 2 | `epic/p2-domain`（未作成） | 未着手 | api の基盤 → A / B / C。F-01〜F-19 |
| p3-front | 3 | `epic/p3-front`（未作成） | 未着手 | web の WA / WB / WC。動線 10 ステップが画面で通る（前半完了） |
| p4-async | 4 | `epic/p4-async`（未作成） | 未着手 | F-20 → F-21 → F-22 → F-23 → F-19 キャッシュ。直列。着手前に人が `.env` を P4 の値に切り替える |
| p5-finish | 5 | `epic/p5-finish`（未作成） | 未着手 | F-24、README、docs の「想定と実際」 |
| p6-reverb | 6 | `epic/p6-reverb`（未作成） | 未着手（Could） | F-25 Broadcasting |

各エピックは人が `/epic-flow <エピック名>` で起動する。フェーズの移行は人が `.claude/.phase` を書き換える。

## タスク

状態の値: `未着手` / `進行中` / `レビュー中` / `完了` / `requires-human`

| ID | タスク | 担当 | 所有ファイル | 完了条件 | 状態 | 備考 |
|---|---|---|---|---|---|---|
| G-1 | 初回コミットを作る | リード | — | `git log` に 1 件ある | 完了 | 2026-10-01 に初回コミット済み |
| G-2 | セッション内で Stop フックが発火する | リード | — | 何か軽い依頼をして応答を終えさせ、`.claude/.loop-count` が一瞬できる、または次の G-3 で差し戻される | 進行中 | 第2弾ではここが 0 回だった |
| G-3 | api をわざと赤にして差し戻される | リード | `api/tests/Unit/GateTest.php`（確認後に消す） | `assertSame(1, 2)` のテストを置いて終了しようとすると block される。消すと終了できる。`loop-log.md` に 2 行増える | 進行中 | 手順は docs/04 の 7.2 |
| G-4 | web をわざと赤にして差し戻される | リード | `web/lib/gate.ts`（確認後に消す） | `export const n: number = "x";` を置いて終了しようとすると「web: 型エラー」で block される | 進行中 | 同上 |
| G-5 | 保護ファイルと危険コマンドのガードが効く | リード | — | `api/.env` の編集が拒否される。`git reset --hard` が拒否される | 進行中 | 同上 |
| G-6 | `/issue-flow` をダミー課題で 1 周回す | リード | `api/routes/api.php` ほか | 例: 「`GET /api/health` が `{"data":{"status":"ok"}}` を返す」を issue-flow で実装 → tester → レビュー → 完了まで通り、SubagentStop が発火する | 進行中 | P1 で `install:api` をするとき邪魔にならないよう、終わったら残すか消すかを人が決める |

ゲートが全部通ったら、人が `.claude/.phase` を `1` にして `/epic-flow p1-skeleton` を起動する。
