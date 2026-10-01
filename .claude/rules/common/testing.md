# テスト（常時適用）

- 自動テストは `api/tests/` だけ。`web/` のテストは書かない（決定9）。web は tsc と ESLint、それと手動チェックリストで確認する
- 必須のテストファイル（`docs/01_技術仕様_next.md` 9.1。Stop フックが存在を検査する）
  - フェーズ 3 から: `Feature/ApplicationTest.php`（定員超過）、`Unit/PayrollCalculatorTest.php`（報酬計算）、`Unit/ApplicationStatusTest.php`（状態遷移）、`Feature/AcceptanceScenarioTest.php`（動線 10 ステップ）
  - フェーズ 5 から: `Feature/NotificationIdempotencyTest.php`（二重送信しない）、`Feature/FailedJobTest.php`（失敗の記録と再実行）
- 業務ロジックの追加・変更にはテストを伴わせる。テストなしのサービス変更は差し戻し対象
- 実行は `make api-test`（全体）/ `make api-test FILTER=<名前>`（部分）
- `tests/TestCase.php` の `RefreshDatabase`、`$this->company` / `$this->admin`、`$this->actingAsAdmin()` を使う。HTTP は `getJson` / `postJson` など JSON 系
- テストを通すためにテストを消す・スキップする・条件で逃げることを禁止
- 他社データの分離は機能ごとに 1 本（404 になること）
- 非同期は「積まれたか」を fake（`Queue::fake()` `Bus::fake()` `Mail::fake()`）で、「実行したらどうなるか」をジョブの `handle()` を直接呼んで検証する。両方を 1 本のテストに混ぜない
- 「再実行したら緑になった」は合格ではない。原因を調べる（第2弾の教訓: Faker のロケールが原因だった）
- 成功は自己申告ではなくテスト出力で示す（結果の件数と失敗名を貼る）
