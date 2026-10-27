<?php

use App\Providers\AppServiceProvider;
use App\Providers\GisServiceProvider;
use App\Providers\PrivateMediaServiceProvider;

return [
    AppServiceProvider::class,
    PrivateMediaServiceProvider::class,
    GisServiceProvider::class,
];
