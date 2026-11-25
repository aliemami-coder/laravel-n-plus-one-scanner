<?php

namespace NPlusOneScanner;

use Illuminate\Support\ServiceProvider;
use NPlusOneScanner\Console\ScanCommand;

class NPlusOneScannerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/nplusone.php',
            'nplusone'
        );

        $this->app->singleton(Scanner::class, function ($app) {
            return new Scanner(
                config: $app['config']->get('nplusone', []),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nplusone.php' => config_path('nplusone.php'),
            ], 'nplusone-config');

            $this->commands([
                ScanCommand::class,
            ]);
        }
    }
}
