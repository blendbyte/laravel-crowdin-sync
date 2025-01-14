<?php

namespace Blendbyte\LaravelCrowdinSync\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Blendbyte\LaravelCrowdinSync\LaravelCrowdinSync
 */
class LaravelCrowdinSync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Blendbyte\LaravelCrowdinSync\LaravelCrowdinSync::class;
    }
}
