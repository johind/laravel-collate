<?php

namespace Johind\Collate\Tests;

use Johind\Collate\CollateServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            CollateServiceProvider::class,
        ];
    }
}
