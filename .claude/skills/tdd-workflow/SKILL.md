---
name: tdd-workflow
description: Laravel API での RED→GREEN→REFACTOR の手順。Feature（JSON の HTTP 経由）と Unit の使い分け、テスト名の付け方、TestCase のヘルパ、レスポンスの形の検証、1 サイクル 1 テスト。implementer-api と tester が従う
---

# TDD ワークフロー（Laravel API / myteam 第3弾）

## サイクル
1. **RED**: 受け入れ条件 1 つを表すテストを 1 本書く。`make api-test FILTER=<テスト名>` で **赤を目で確認する**（赤にならないテストは何も検証していない）
2. **GREEN**: テストを通す最小の実装。先回りしない
3. **REFACTOR**: 重複除去・命名・サービスへの移動。緑を保ったまま
4. 次の受け入れ条件へ。1 タスクの終わりに担当クラス全体を `FILTER` で回す

## どこに書くか
| 対象 | 種別 | 場所 | 例 |
|---|---|---|---|
| エンドポイント・検証・認証・テナント分離・レスポンスの形 | Feature（HTTP） | `api/tests/Feature/<機能>Test.php` | `$this->actingAsAdmin()->postJson("/api/applications/{$a->id}/confirm")->assertStatus(422)->assertJsonPath('message', '定員に達しているため承認できません。')` |
| サービスの業務ルール | Unit または Feature | `api/tests/Unit/<Service>Test.php` | `ApplicationService::confirm()` の定員超過 |
| Enum・純粋計算 | Unit | `api/tests/Unit/<Enum|Calculator>Test.php` | `PayrollCalculator::calculate()` |
| 動線 10 ステップ | Feature 1 本 | `api/tests/Feature/AcceptanceScenarioTest.php` | 要件 5 章を API 呼び出しでそのまま |
| 非同期 | Feature | `api/tests/Feature/<機能>Test.php` | `async-rules` スキルの「テストの型」 |

## 書き方の規約
- テスト名は仕様が読める日本語: `test_定員に達していると承認できない`。Pint が英字部分を snake_case に直すので、**日本語だけで書く**（英字のクラス名を混ぜない）
- `tests/TestCase.php` の `$this->company` / `$this->admin` / `$this->actingAsAdmin()` を使う。`RefreshDatabase` と `CurrentCompany::set()` は適用済み
- HTTP は JSON 系（`getJson` `postJson` `putJson` `patchJson` `deleteJson`）だけ
- 形の検証: 成功は `assertJsonStructure(['data' => ['id', 'name', ...]])` と `assertJsonPath('data.status.value', 'confirmed')`。失敗は `assertStatus(422)->assertJsonValidationErrors(['name'])` または `assertJsonPath('message', '...')`
- 未ログインは 401、他社データは 404。機能ごとに 1 本ずつ
- 別企業のデータを作るときは `CurrentCompany::runAs($other->id, fn () => Location::factory()->create())`
- ファクトリの state を使う: `Worker::factory()->inactive()`、`Application::factory()->confirmed()`
- 設定はテスト内で上書き: `config(['myteam.min_wage' => 1300])`
- 時刻依存は明示的な日付を入力に使う。スケジューラやリマインドのように「今」に依存するものだけ `$this->travelTo()` を使う

## やってはいけない
- テストを通すために `markTestSkipped` や条件分岐で逃げる
- 1 テストに複数の受け入れ条件を詰める
- 実装を先に書いてからテストで「動くこと」をなぞる
- 「再実行したら緑」を合格にする
