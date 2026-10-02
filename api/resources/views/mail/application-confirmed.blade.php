{{--
  ワーカー宛ての確定通知（F-20）。ワーカーはログインできないので、リンクを 1 つも置かない
  （管理画面の URL を外部に配らない。リードの決定）。既定の x-mail::message はヘッダーに APP_URL へのリンクを
  入れるので使わず、x-mail::layout にリンクのないヘッダーとフッターを渡す。
  差し込む値は AppServiceProvider の Markdown::withSecuredEncoding() で Markdown の記号もエスケープされる
--}}
<x-mail::layout>
<x-slot:header>
<tr>
<td class="header">{{ config('app.name') }}</td>
</tr>
</x-slot:header>
# 勤務が確定しました

{{ $workerName }} 様

ご応募いただいた募集の勤務が確定しました。当日はよろしくお願いいたします。

<x-mail::table>
| 項目 | 内容 |
|:--|:--|
| 日時 | {{ $workDate }} {{ $startTime }}〜{{ $endTime }} |
| 拠点 | {{ $locationName }} |
| 住所 | {{ $locationAddress }} |
| 職種 | {{ $jobTitle }} |
| 時給 | {{ $hourlyWage }}円 |
</x-mail::table>

ご不明な点は、募集を出した企業の担当者へお問い合わせください。このメールは送信専用です。
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
