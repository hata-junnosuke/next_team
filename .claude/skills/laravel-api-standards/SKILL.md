---
name: laravel-api-standards
description: myteam 第3弾の Laravel API 実装規約。層の分け方（ルート → FormRequest → コントローラ → サービス → Resource）、エラー形式、Resource の書き方、ページネーション、テナント分離、Sanctum + Fortify の設定。implementer-api と code-reviewer が従う
---

# Laravel API 実装規約（myteam 第3弾）

契約（クラス名・フィールド名）は `docs/01_技術仕様_next.md` が正。ここは書き方。

## 層
| 層 | 置き場所 | やること | やらないこと |
|---|---|---|---|
| ルート | `routes/api.php` | `auth:sanctum` + `company` のグループに登録。名前を付ける（`locations.index`） | クロージャで処理を書く |
| FormRequest | `app/Http/Requests` | `rules()` / `attributes()`（日本語項目名）/ `after()`（項目間検査）。自社データの存在検証は `Rule::exists(...)->where('company_id', CurrentCompany::id())` | 業務ルール（定員・状態） |
| コントローラ | `app/Http/Controllers/Api` | FormRequest を受ける → サービス or Eloquent → Resource を返す | `try/catch`、`$request->validate()`、`redirect()` |
| サービス | `app/Services` | 業務ルール、`DB::transaction`、`DomainException`。結果は モデル or readonly DTO | HTTP（Request / Response）に触る |
| Resource | `app/Http/Resources` | モデル → JSON の形。docs/01 の 6 章どおり | クエリを発行する（N+1 の元。`whenLoaded` / `whenCounted` を使う） |
| モデル | `app/Models` | リレーション、cast、スコープ、`BelongsToCompany`、`transitionTo()` | 業務ルールの判断 |

単純な CRUD（拠点・ワーカーの登録と更新）はサービスを作らず、コントローラから Eloquent を直接呼んでよい。**ルールがあるものだけサービスにする**。

## コントローラの形
```php
public function confirm(Application $application, ApplicationService $service): ApplicationResource
{
    return new ApplicationResource($service->confirm($application)->load('worker', 'attendance'));
}

public function store(LocationRequest $request): JsonResponse
{
    $location = Location::create($request->validated());

    return (new LocationResource($location))->response()->setStatusCode(201);
}
```
`DomainException` は捕まえない。`bootstrap/app.php` の `withExceptions` が 422 に変換する。

## エラー形式（`bootstrap/app.php`）
```php
$exceptions->render(function (DomainException $e, Request $request) {
    return response()->json([
        'message' => $e->getMessage(),
        'errors' => $e->field ? [$e->field => [$e->getMessage()]] : (object) [],
    ], 422);
});
```
- 検証エラー（`ValidationException`）は Laravel 既定の `{message, errors}` のまま
- 404 のメッセージは「見つかりませんでした。」に統一する（モデル名を出さない）
- 401 は `{"message": "Unauthenticated."}` のまま（web はステータスだけ見る）

## Resource
```php
return [
    'id' => $this->id,
    'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
    'work_date' => $this->work_date->toDateString(),          // "2026-09-20"
    'start_time' => substr($this->start_time, 0, 5),          // "09:00"
    'confirmed_at' => $this->confirmed_at?->toIso8601String(),
    'location' => new LocationSummaryResource($this->whenLoaded('location')),
    'confirmed_count' => $this->whenCounted('confirmedApplications'),
    'can' => ['edit' => $this->status !== JobPostingStatus::Cancelled, ...],
];
```
- 一覧は `XxxResource::collection($query->paginate($perPage))`。`per_page` は `min((int) $request->query('per_page', 20), 100)`
- ページなしの一覧は `XxxResource::collection($query->get())`

## テナント分離
- `BelongsToCompany` を付けたモデルは、何もしなくても自社のデータだけになる。ルートモデルバインディングもスコープを通るので他社の ID は 404
- `CurrentCompany` を設定するのは `SetCurrentCompany` ミドルウェア・スケジューラのコマンド・テストだけ。サービスやコントローラで `set()` しない
- join 先のテーブルにはスコープが効かない。`->where('job_postings.company_id', CurrentCompany::id())` を明示する

## 認証
- `php artisan install:api` → Sanctum。`composer require laravel/fortify` → `php artisan fortify:install`。**Fortify が作るビュー関連・2FA のマイグレーションは消す**
- `config/fortify.php`: `'views' => false`、`'features' => []`
- `bootstrap/app.php`: `$middleware->statefulApi();`、`$middleware->alias(['company' => SetCurrentCompany::class]);`
- `config/cors.php` は `php artisan config:publish cors` で出す
- ログインのテスト: `postJson('/login', [...])` が 200、6 回目が 429、失敗が 422 で `errors.email`

## 整形・命名
- Pint 準拠（PostToolUse フックが自動整形）。コメントは日本語
- コントローラは `App\Http\Controllers\Api\<名前>Controller`。単一アクションに分けない（リソースコントローラ + 追加アクション）
- `dd()` / `dump()` を残さない（フックが差し戻す）

## 参考（日本語）
- API リソース: https://readouble.com/laravel/13.x/ja/eloquent-resources.html
- Sanctum（SPA 認証）: https://readouble.com/laravel/13.x/ja/sanctum.html
- Fortify: https://readouble.com/laravel/13.x/ja/fortify.html
- エラー処理: https://readouble.com/laravel/13.x/ja/errors.html
- Context: https://readouble.com/laravel/13.x/ja/context.html
