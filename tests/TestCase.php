<?php

namespace Johind\Collate\Tests;

use Johind\Collate\CollateServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CollateServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('collate.binary_path', 'qpdf');
        $app['config']->set('collate.temp_directory', sys_get_temp_dir().'/collate-tests');
    }
}
