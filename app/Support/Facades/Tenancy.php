<?php

namespace App\Support\Facades;

use App\Tenancy\TenancyManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \App\Models\Tenant|null current()
 * @method static \App\Models\Tenant currentOrFail()
 * @method static int|null id()
 * @method static int idOrFail()
 * @method static void set(\App\Models\Tenant|null $tenant)
 * @method static void clear()
 * @method static mixed runAs(\App\Models\Tenant $tenant, \Closure $callback)
 * @method static mixed withoutScope(\Closure $callback)
 * @method static bool isBypassed()
 * @method static void eachActiveTenant(\Closure $callback)
 *
 * @see TenancyManager
 */
class Tenancy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TenancyManager::class;
    }
}
