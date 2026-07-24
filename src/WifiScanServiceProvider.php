<?php

declare(strict_types=1);

namespace WifiScan\Mobile;

use Illuminate\Support\ServiceProvider;
use WifiScan\Mobile\Contracts\WifiInterface;

class WifiScanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/wifi-scan.php',
            'wifi-scan',
        );

        $this->app->singleton(WifiInterface::class, fn (): Wifi => new Wifi);
        $this->app->alias(WifiInterface::class, Wifi::class);
        $this->app->alias(WifiInterface::class, 'wifi');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/wifi-scan.php' => config_path('wifi-scan.php'),
            ], 'wifi-scan-config');
        }
    }
}
