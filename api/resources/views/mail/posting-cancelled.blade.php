{{--
  ワーカー宛ての中止通知（F-21）。確定通知と同じく、リンクを 1 つも置かない（ワーカーはログインできない）。
  x-mail::message はヘッダーに APP_URL へのリンクを入れるので、x-mail::layout にリンクのないヘッダーとフッターを渡す。
  差し込む値は AppServiceProvider の Markdown::withSecuredEncoding() で Markdown の記号もエスケープされる
--}}
<x-mail::layout>
<x-slot:header>
<tr>
<td class="header">{{ config('app.name') }}</td>
</tr>
</x-slot:header>
# 募集が中止になりました

{{ $workerName }} 様

確定していた下記の勤務は、募集が中止になったためキャンセルとなりました。ご迷惑をおかけして申し訳ございません。

<x-mail::table>
| 項目 | 内容 |
|:--|:--|
| 日時 | {{ $workDate }} {{ $startTime }}〜{{ $endTime }} |
| 拠点 | {{ $locationName }} |
| 住所 | {{ $locationAddress }} |
| 職種 | {{ $jobTitle }} |
| キャンセル理由 | {{ $cancelReason }} |
</x-mail::table>

ご不明な点は、募集を出した企業の担当者へお問い合わせください。このメールは送信専用です。
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
