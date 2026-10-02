---
paths:
  - "api/app/**"
  - "api/database/**"
  - "api/routes/**"
  - "api/tests/**"
  - "api/config/**"
  - "api/bootstrap/**"
---
# Laravel 実装ルール（`api/` を編集するときに適用）

- Pint 準拠（PostToolUse フックが自動整形）。`declare(strict_types=1)` は付けない（Laravel 既定に合わせる）
- **JSON しか返さない**。Blade のビュー、`redirect()`、`back()`、セッションのフラッシュは使わない
- 入力検証は FormRequest。コントローラで `$request->validate()` を書かない
- 業務ルールはサービス（`app/Services`）。コントローラは「FormRequest → サービス → Resource」だけ。`try/catch` は書かない（`DomainException` は `bootstrap/app.php` が 422 に変換する）
- レスポンスは API Resource。モデルや配列を直接返さない。Enum は `['value' => ..., 'label' => ...]` で返す
- モデルは `#[Fillable([...])]` 属性。`company_id` `status` は含めない
- `status` の変更は `transitionTo()` だけ。直接代入・`update(['status' => ...])` を書かない
- 企業の判定は `CurrentCompany::id()`。`auth()->user()->company_id` を各所に書かない
- 認証は Fortify に任せる。`Auth::attempt()` を自前で書かない
- 月次集計は SQL（join + groupBy + SUM）。PHP 側で foreach 合計しない。日付の範囲は半開区間。`whereBetween` `whereDate` は使わない
- 勤怠の日時組み立て（深夜跨ぎ判定）は `AttendanceService` の 1 箇所で行う
- Enum は backed enum + `label()`。cast は `casts()` メソッドで
- ファクトリは state（`->inactive()` `->confirmed()`）で状況を表す
- 日付は `CarbonImmutable`。タイムゾーンは `config('app.timezone')` = Asia/Tokyo

## 非同期（P4 から）
- ジョブのコンストラクタには ID（int）を渡す。モデルを渡さない
- ジョブの中に業務ルールを書かない。サービスを呼ぶ
- メールを送るジョブは `NotificationLogService::claim()` → 送信 → `markSent()` の順を守る
- イベントは `ShouldDispatchAfterCommit` を実装する
- 試行回数の設定（メールは `$tries = 0` + `$maxExceptions` + `retryUntil()`、それ以外は `$tries`）と `backoff()` `failed()` を必ず書く。キュー名は `mails` か `default` を明示する
- 詳細は `.claude/skills/async-rules/SKILL.md` と `docs/03_非同期処理の設計.md`
