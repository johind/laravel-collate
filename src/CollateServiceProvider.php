<?php

declare(strict_types=1);

namespace Johind\Collate;

use Johind\Collate\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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
        $this->app->singleton(Collate::class, fn ($app): Collate => new Collate(
            binaryPath: config('collate.binary_path', 'qpdf'),
            disk: config('collate.default_disk'),
            tempDirectory: config('collate.temp_directory', storage_path('app/collate')),
        ));
    }
}
