<?php

use App\Models\TenantInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TenantInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired tenant invitations');
