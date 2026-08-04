<?php

namespace App\Support\Facades;

use App\Models\Team;
use App\Models\User;
use App\Support\TeamManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void initialize(Team $team)
 * @method static void actingAs(?User $user)
 * @method static void forget()
 * @method static Team|null current()
 * @method static Team currentOrFail()
 * @method static string|null currentId()
 * @method static string currentIdOrFail()
 * @method static bool initialized()
 *
 * @see TeamManager
 */
class Teams extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TeamManager::class;
    }
}
