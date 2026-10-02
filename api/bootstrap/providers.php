<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;

// TelescopeServiceProvider はここに書かない（開発用。ローカル環境だけ AppServiceProvider::register() で登録する）
return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
];
