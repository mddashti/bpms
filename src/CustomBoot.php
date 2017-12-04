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
            'database' => ProcessLogicInterface::CONFIG_BOOT_DATABASE,
            'username' => ProcessLogicInterface::CONFIG_BOOT_USERNAME,
            'password' => ProcessLogicInterface::CONFIG_BOOT_PASSWORD,
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->bootEloquent();
    }

}