<?php

/*
 * myteam の業務設定。コードに直接数字を書かず config('myteam.xxx') で読む（Rails の config/x や Settings に相当）。
 * https://readouble.com/laravel/13.x/ja/configuration.html
 */
return [

    // F-05: 募集の時給の下限（円）。最低賃金の改定は .env の MYTEAM_MIN_WAGE で変える
    'min_wage' => (int) env('MYTEAM_MIN_WAGE', 1226),

];
