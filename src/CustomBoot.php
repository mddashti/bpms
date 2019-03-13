<?php
namespace Niyam\Bpms;

use Illuminate\Database\Capsule\Manager;

class CustomBoot
{
    public static function enable($options = null)
    {
        $capsule = new Manager();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => 'localhost',
            'database' => $options['database'] ?: ProcessLogic::CONFIG_BOOT_DATABASE,
            'username' => $options['username'] ?: ProcessLogic::CONFIG_BOOT_USERNAME,
            'password' => $options['password'] ?: ProcessLogic::CONFIG_BOOT_PASSWORD,
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->bootEloquent();
    }
}

