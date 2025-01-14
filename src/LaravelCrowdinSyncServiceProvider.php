<?php

namespace Blendbyte\LaravelCrowdinSync;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelCrowdinSyncServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-crowdin-sync')
            ->hasConfigFile();
    }
}
