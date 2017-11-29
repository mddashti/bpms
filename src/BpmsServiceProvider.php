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
    {
        $this->registerMigrations();
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    protected function registerMigrations()
    {
        //dd(__DIR__ . '/migrations');
        return $this->loadMigrationsFrom(__DIR__ . '/migrations');
    }
}
