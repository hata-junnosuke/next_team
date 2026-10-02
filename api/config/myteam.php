<?php

/*
 * myteam の業務設定。コードに直接数字を書かず config('myteam.xxx') で読む（Rails の config/x や Settings に相当）。
 * https://readouble.com/laravel/13.x/ja/configuration.html
 */
return [

    // F-05: 募集の時給の下限（円）。最低賃金の改定は .env の MYTEAM_MIN_WAGE で変える
    'min_wage' => (int) env('MYTEAM_MIN_WAGE', 1226),

    // F-21: メール送信の 1 分あたりの上限（全社共通。RateLimiter::for('mails')）。メールサーバーの制限に合わせて .env の MYTEAM_MAILS_PER_MINUTE で変える
    'mails_per_minute' => (int) env('MYTEAM_MAILS_PER_MINUTE', 60),

    // F-20〜F-22: メールのジョブを積んでから再試行をあきらめるまでの分数（ジョブの retryUntil()）。過ぎたら failed() に入る
    'mail_retry_until_minutes' => (int) env('MYTEAM_MAIL_RETRY_UNTIL_MINUTES', 60),

    // F-22: 前日リマインドを積む時刻（"HH:MM"、Asia/Tokyo）。routes/console.php のスケジュールが読む。.env の MYTEAM_REMINDER_TIME で変える
    'reminder_time' => env('MYTEAM_REMINDER_TIME', '18:00'),

    // F-23: 集計出力が queued / processing のまま止まったとみなす分数。これより古い生成中は、同じ月の新しい依頼を受けたときに failed にする
    // （ワーカーが落ちた・ジョブが消えた出力で、その月が 422 のまま塞がらないように）。.env の MYTEAM_REPORT_EXPORT_STALE_MINUTES で変える
    // 下限 5 分: 0 や小さすぎる値だと、動いている出力まで同じ月の依頼で failed にしてしまう
    'report_export_stale_minutes' => max(5, (int) env('MYTEAM_REPORT_EXPORT_STALE_MINUTES', 30)),

];
