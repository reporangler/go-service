<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middleware = [];

    protected $middlewareGroups = [
        'api' => [],
    ];

    protected $middlewareAliases = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'cors' => \App\Http\Middleware\Cors::class,
    ];
}
