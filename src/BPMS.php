<?php namespace Niyam\Bpms;

use Illuminate\Support\Facades\Route;


class BPMS
{
    public static function routes($callback = null, array $options = [])
    {
        $callback = $callback ?: function ($router) {
            $router->all();
        };

        $defaultOptions = [
            'prefix' => 'bpms',
            'namespace' => '\Niyam\Bpms\Http\Controllers',
        ];

        $options = array_merge($defaultOptions, $options);

        Route::group($options, function ($router) use ($callback) {
            $callback(new RouteRegistrar($router));
        });
    }
}

