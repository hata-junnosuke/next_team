# テスト（常時適用）

- 自動テストは `api/tests/` だけ。`web/` のテストは書かない。web は tsc と ESLint、それと issue に書いた画面の確認項目を人が確かめる
- 必須のテストファイル 6 本（`docs/01_技術仕様_next.md` の必須のテストファイル。Stop フックが常に存在を検査する）
  - 動線: `Feature/ApplicationTest.php`（定員超過）、`Unit/PayrollCalculatorTest.php`（報酬計算）、`Unit/ApplicationStatusTest.php`（状態遷移）、`Feature/AcceptanceScenarioTest.php`（動線 10 ステップ）
  - 非同期: `Feature/NotificationIdempotencyTest.php`（二重送信しない）、`Feature/FailedJobTest.php`（失敗の記録と再実行）
- 業務ロジックの追加・変更にはテストを伴わせる。テストなしのサービス変更は差し戻し対象
- 実行は `make api-test`（全体）/ `make api-test FILTER=<名前>`（部分）
- `tests/TestCase.php` の `RefreshDatabase`、`$this->company` / `$this->admin`、`$this->actingAsAdmin()` を使う。HTTP は `getJson` / `postJson` など JSON 系
- テストを通すためにテストを消す・スキップする・条件で逃げることを禁止
- 他社データの分離は機能ごとに 1 本（404 になること）
- 非同期は「積まれたか」を fake（`Queue::fake()` `Bus::fake()` `Mail::fake()`）で、「実行したらどうなるか」をジョブの `handle()` を直接呼んで検証する。両方を 1 本のテストに混ぜない
- 「再実行したら緑になった」は合格ではない。原因を調べる（例: Faker のロケールや乱数で確率的に赤になる）
- 期待値に関わる列には、ファクトリの `fake()->optional()` の値を使わない。テストの中で列を明示して作る（乱数で 2 回に 1 回だけ赤になる）
- 成功は自己申告ではなくテスト出力で示す（結果の件数と失敗名を貼る）
