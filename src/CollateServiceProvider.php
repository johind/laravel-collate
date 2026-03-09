<?php

namespace Johind\Collate;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Johind\Collate\Commands\InstallCommand;

class CollateServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('collate')
            ->hasConfigFile()
            ->hasCommand(InstallCommand::class);
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(Collate::class, function ($app) {
            return new Collate(
                binaryPath: config('collate.binary_path', 'qpdf'),
                disk: config('collate.default_disk'),
                tempDirectory: config('collate.temp_directory', storage_path('app/collate')),
            );
        });
    }
}
