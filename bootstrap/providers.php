<?php

use App\Providers\AppServiceProvider;
use App\Providers\CacheServiceProvider;
use App\Providers\TenancyServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    CacheServiceProvider::class,
    PermissionServiceProvider::class,
];
