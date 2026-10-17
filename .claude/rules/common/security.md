# セキュリティ（常時適用）

## テナント分離
- 企業の判定は `CurrentCompany::id()` だけを使う。サービスやジョブの中で `auth()->user()` を見ない（ジョブにはログインユーザーがいない）
- `withoutGlobalScopes()` / `withoutGlobalScope('company')` を使う行には理由コメントを必ず書く
- join するクエリは、グローバルスコープが効かない側のテーブルにも `company_id` 条件を明示する
- ポリモーフィックな関連（例: `notification_logs` の宛先と対象）は「保存時に親が自社か」の検査が効かない。サービスの入口で、相手が自社のものかを確かめる
- キャッシュのキーには企業ID を含める（`dashboard:{company_id}`）
- `#[Fillable]` に `company_id` と `status` を入れない

## 認証
- 認証は Fortify に任せる。`Auth::attempt()` を自前で書かない。`config/fortify.php` の `features` は空、`views` は `false`
- `RateLimiter::for('login')` を消さない
- `/api/*` はすべて `auth:sanctum` + `company` ミドルウェアの中。例外は署名付きURL のダウンロードだけ（`signed` ミドルウェア必須）
- `/horizon` と `/telescope` は Gate でログイン済み管理者・ローカル環境に限定する

## web
- ブラウザから Laravel を直接叩くのはログインとログアウトと、F-23 の署名付きダウンロード URL を開くことだけ。それ以外は `lib/api.ts` 経由（`server-only`）
- Server Action は公開エンドポイントと同じ。引数を信用しない。認可は Laravel の応答（401 / 404 / 422）に任せる
- `dangerouslySetInnerHTML` を使わない
- `NEXT_PUBLIC_` を付けてよい環境変数は `NEXT_PUBLIC_API_URL` だけ

## 共通
- `.env` / `.env.local` を読まない・書かない・値を docs に書かない（`.env.example` を編集し、人が反映）
- 秘密情報や個人情報をコミットしない（シーダーの管理者は `admin@example.com / password` のダミーのみ）
- CSV のセルが `=` `+` `-` `@` タブ CR で始まるときは先頭に `'` を付ける
- ジョブのペイロードとログにパスワードや Cookie を入れない
