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

];
