{{--
  ワーカー宛ての前日リマインド（F-22）。確定通知・中止通知と同じく、リンクを 1 つも置かない（ワーカーはログインできない）。
  x-mail::message はヘッダーに APP_URL へのリンクを入れるので、x-mail::layout にリンクのないヘッダーとフッターを渡す。
  差し込む値は AppServiceProvider の Markdown::withSecuredEncoding() で Markdown の記号もエスケープされる
--}}
<x-mail::layout>
<x-slot:header>
<tr>
<td class="header">{{ config('app.name') }}</td>
</tr>
</x-slot:header>
# 勤務のお知らせ

{{ $workerName }} 様

確定している下記の勤務のお知らせです。当日はよろしくお願いいたします。

<x-mail::table>
| 項目 | 内容 |
|:--|:--|
| 日時 | {{ $workDate }} {{ $startTime }}〜{{ $endTime }} |
| 拠点 | {{ $locationName }} |
| 住所 | {{ $locationAddress }} |
| 職種 | {{ $jobTitle }} |
</x-mail::table>

ご都合が悪くなった場合は、募集を出した企業の担当者へご連絡ください。このメールは送信専用です。
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
