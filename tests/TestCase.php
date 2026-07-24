<?php

declare(strict_types=1);

namespace WifiScan\Mobile\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use WifiScan\Mobile\WifiScanServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            WifiScanServiceProvider::class,
        ];
    }
}
