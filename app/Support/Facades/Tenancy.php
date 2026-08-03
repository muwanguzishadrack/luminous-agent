<?php

namespace App\Support\Facades;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenancyManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void initialize(Tenant $tenant)
 * @method static void actingAs(?User $user)
 * @method static void forget()
 * @method static Tenant|null current()
 * @method static string|null currentId()
 * @method static string currentIdOrFail()
 * @method static bool initialized()
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
