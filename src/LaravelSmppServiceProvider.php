<?php

namespace Kstmostofa\LaravelSmpp;

use Illuminate\Support\ServiceProvider;

class LaravelSmppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/smpp.php',
            'smpp'
        );

        $this->app->singleton('smpp', function ($app) {
            $config = $app['config']->get('smpp');
            return new SmppClient(
                $config['host'],
                $config['port'],
                $config['username'],
                $config['password'],
                $config['timeout'],
                $config['debug']
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/config/smpp.php' => config_path('smpp.php'),
        ], 'smpp-config');
    }
}
