# STATE — エピックとタスクの状態（5+1 の「メモリ」）

セッションをまたいで引き継ぐ状態。`planner` が書き、`issue-flow` が更新し、`session-context.sh` が起動時に読む。

## エピック

| エピック | フェーズ | ブランチ | 状態 | 備考 |
|---|---|---|---|---|
| p0-harness | 0 | main | ゲート通過（人の確認待ち） | 2026-10-01 に G-1〜G-6 が通過。モデル方針を「計画 = fable、それ以外 = opus」に変更。担当の稼働中は Stop の検査を飛ばす仕組み（SubagentStart）を追加。残りは人の作業: `.claude/.phase` を 1 に → `/epic-flow p1-skeleton` |
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
| G-2 | セッション内で Stop フックが発火する | リード | — | 何か軽い依頼をして応答を終えさせ、`.claude/.loop-count` が一瞬できる、または次の G-3 で差し戻される | 完了 | 2026-10-01: セッション内で発火し、終了が差し戻された（第2弾は 0 回） |
| G-3 | api をわざと赤にして差し戻される | リード | `api/tests/Unit/GateTest.php`（確認後に消す） | `assertSame(1, 2)` のテストを置いて終了しようとすると block される。消すと終了できる。`loop-log.md` に 2 行増える | 完了 | 2026-10-01:「api: PHPUnit 失敗」で block（1/5）。消して緑。loop-log に記録 |
| G-4 | web をわざと赤にして差し戻される | リード | `web/lib/gate.ts`（確認後に消す） | `export const n: number = "x";` を置いて終了しようとすると「web: 型エラー」で block される | 完了 | 2026-10-01:「web: 型エラー」で block（G-3 と同じ回で同時に確認） |
| G-5 | 保護ファイルと危険コマンドのガードが効く | リード | — | `api/.env` の編集が拒否される。`git reset --hard` が拒否される | 完了 | 2026-10-01: git の強制リセット・`vendor/`・`.phase` はフックが拒否、`api/.env` は permissions の deny が拒否 |
| G-6 | `/issue-flow` をダミー課題で 1 周回す | リード | `api/routes/api.php` ほか | 例: 「`GET /api/health` が `{"data":{"status":"ok"}}` を返す」を issue-flow で実装 → tester → レビュー → 完了まで通り、SubagentStop が発火する | 完了 | 2026-10-01: implementer-api → tester → spec ∥ code ∥ security を直列で 1 周。レビュー 0 周（重大なし）。SubagentStop は 5 体すべてで発火。テスト 4 件緑。ダミー一式は確認後に削除した（人の判断。P1 の `install:api` と重ならないようにするため） |

ゲートが全部通ったら、人が `.claude/.phase` を `1` にして `/epic-flow p1-skeleton` を起動する。
