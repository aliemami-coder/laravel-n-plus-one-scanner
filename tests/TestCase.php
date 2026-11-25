<?php

namespace NPlusOneScanner\Tests;

use NPlusOneScanner\NPlusOneScannerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            NPlusOneScannerServiceProvider::class,
        ];
    }
}
