<?php

namespace Johind\Collate\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Johind\Collate\CollateServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            CollateServiceProvider::class,
        ];
    }
}
