<?php

namespace Niyam\Bpms;

use Illuminate\Support\ServiceProvider;

class BpmsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    { }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('Niyam\Bpms\Data\DataRepositoryInterface', 'Niyam\Bpms\Service\ProcessService');
        if (env('BPMS_ENABLE_MIGRATION', true))
            $this->registerMigrations();
    }

    protected function registerMigrations()
    {
        return $this->loadMigrationsFrom(__DIR__ . '/Migrations');
    }
}
