# セキュリティ（常時適用）

## テナント分離
- 企業の判定は `CurrentCompany::id()` だけを使う。サービスやジョブの中で `auth()->user()` を見ない（ジョブにはログインユーザーがいない）
- `withoutGlobalScopes()` / `withoutGlobalScope('company')` を使う行には理由コメントを必ず書く。今ある・予定している場所は、署名付きURL のダウンロード、`BelongsToCompany` の保存時の親の検査、ワーカーの初回登録で招待を引く所、Sanctum がトークンの持ち主のワーカーを読む所（`PersonalAccessToken::tokenable()`）だけ（docs/01 の 4.2・4.5・5.3）。ワーカーのログインは企業コードで `Company` を引いて `CurrentCompany::runAs()` で動くので、外さない
- ワーカーの HTTP では `worker` ミドルウェアがログイン中のワーカーの `company_id` を `CurrentCompany` に入れる（管理者の `company` ミドルウェアと同じ役目）
- join するクエリは、グローバルスコープが効かない側のテーブルにも `company_id` 条件を明示する
- ポリモーフィックな関連（例: `notification_logs` の宛先と対象）は「保存時に親が自社か」の検査が効かない。サービスの入口で、相手が自社のものかを確かめる
- キャッシュのキーには企業ID を含める（`dashboard:{company_id}`）
- `#[Fillable]` に `company_id` と `status` を入れない

## 認証
- 管理者の認証は Fortify に任せる。`Auth::attempt()` を自前で書かない。`config/fortify.php` の `features` は空、`views` は `false`
- **例外はワーカーの認証だけ**: ワーカー（`mobile/`）は Sanctum の API トークンで、ログインと初回登録は `WorkerAuthService` が Sanctum の公式手順（`Hash::check()` → `createToken()`）で書く（docs/01 の 5.3、docs/00 の決定 57）。ほかの場所でトークンを発行しない
- `RateLimiter::for('login')` と、ワーカーの `worker-login` `worker-register` を消さない
- 管理者向けの `/api/*` はすべて `auth:sanctum` + `company` ミドルウェアの中。例外は署名付きURL のダウンロードだけ（`signed` ミドルウェア必須）
- ワーカー向けの `/api/worker/*` はすべて `auth:worker` + `worker` ミドルウェアの中。例外は `register` と `login` だけ（`throttle` 必須）
- ワーカーのトークンで管理者の API に、管理者の Cookie でワーカーの API に入れないことを、テストで守る（docs/01 の 5.3「入れないことの確認」）。guard の provider だけに頼らず、`company` と `worker` のミドルウェアでもモデルの型を確かめる
- 招待コードは HMAC（引くため）と暗号化した列（メールに書くため）でだけ持ち、平文で保存しない。ジョブには招待の ID だけを渡し、招待メールの Mailable を `ShouldQueue` にしない
- 認証の外の公開エンドポイント（ワーカーの `register` `login`）は、応答の文言と応答時間でアカウントの有無を明かさない（判定の順を docs/01 の 4.5 のとおりにし、`login` は `Timebox` で包む。FormRequest に `unique` を書かない）
- Telescope にトークンと招待コードを残さない（`TelescopeServiceProvider` の `hideRequestParameters` / `hideResponseParameters`。docs/01 の 5.3）
- `/horizon` と `/telescope` は Gate でログイン済み管理者・ローカル環境に限定する

## web
- ブラウザから Laravel を直接叩くのはログインとログアウトと、F-23 の署名付きダウンロード URL を開くことだけ。それ以外は `lib/api.ts` 経由（`server-only`）
- Server Action は公開エンドポイントと同じ。引数を信用しない。認可は Laravel の応答（401 / 404 / 422）に任せる
- `dangerouslySetInnerHTML` を使わない
- `NEXT_PUBLIC_` を付けてよい環境変数は `NEXT_PUBLIC_API_URL` だけ

## mobile（ワーカーアプリ）
- トークンは `flutter_secure_storage`（iOS では Keychain）にだけ保存する。`SharedPreferences` やファイルに置かない
- トークン・パスワード・招待コードをログに出さない（`print` / `debugPrint`、`dio` のログ出力。リクエストのヘッダと本文を丸ごと出さない）
- `--dart-define` に秘密を入れない（ビルドに埋め込まれ、アプリから取り出せる）。入れてよいのは `API_BASE_URL` のような公開してよい値だけ
- 業務ルールと検証をアプリに書かない。可否は API の 401 / 404 / 422 に任せ、422 の `message` を表示する。401 ならトークンを消してログイン画面に戻す

## 共通
- `.env` / `.env.local` を読まない・書かない・値を docs に書かない（`.env.example` を編集し、人が反映）
- 秘密情報や個人情報をコミットしない（シーダーの管理者は `admin@example.com / password` のダミーのみ）
- CSV のセルが `=` `+` `-` `@` タブ CR で始まるときは先頭に `'` を付ける
- ジョブのペイロードとログにパスワードや Cookie を入れない
