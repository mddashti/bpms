<?php
namespace Niyam\Bpms;

use Illuminate\Database\Capsule\Manager;

class CustomBoot
{
    public static function enable()
    {
        $capsule = new Manager();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => 'localhost',
            'database' => ProcessLogic::CONFIG_BOOT_DATABASE,
            'username' => ProcessLogic::CONFIG_BOOT_USERNAME,
            'password' => ProcessLogic::CONFIG_BOOT_PASSWORD,
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->bootEloquent();
    }

}