{{--
  ワーカー宛ての招待メール（F-26）。アプリの初回登録に使う招待コード・有効期限・企業コードを書く。
  ほかのワーカー宛てのメールと同じく、リンクを 1 つも置かない（管理画面の URL を外部に配らない）。既定の x-mail::message は
  ヘッダーに APP_URL へのリンクを入れるので使わず、x-mail::layout にリンクのないヘッダーとフッターを渡す。
  差し込む値は AppServiceProvider の Markdown::withSecuredEncoding() で Markdown の記号もエスケープされる
--}}
<x-mail::layout>
<x-slot:header>
<tr>
<td class="header">{{ config('app.name') }}</td>
</tr>
</x-slot:header>
# ワーカーアプリへの招待

{{ $workerName }} 様

ワーカーアプリの初回登録のご案内です。アプリの「初回登録」で、このメールアドレスと次の招待コードを入力し、パスワードを決めてください。
登録の後のログインには、企業コード・このメールアドレス・決めたパスワードを使います。

<x-mail::table>
| 項目 | 内容 |
|:--|:--|
| 企業 | {{ $companyName }} |
| 招待コード | {{ $invitationCode }} |
| 有効期限 | {{ $expiresAt }} |
| 企業コード | {{ $companyCode }} |
</x-mail::table>

有効期限を過ぎた場合や、心当たりのない場合は、企業の担当者へお問い合わせください。招待コードは他の人に教えないでください。このメールは送信専用です。
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
