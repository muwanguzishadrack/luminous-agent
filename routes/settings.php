<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Tenants\TenantController;
use App\Http\Controllers\Tenants\TenantInvitationController;
use App\Http\Controllers\Tenants\TenantMemberController;
use App\Http\Middleware\EnsureTenantMembership;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::post('settings/tenants', [TenantController::class, 'store'])->name('tenants.store');

    Route::middleware(EnsureTenantMembership::class)->group(function () {
        Route::get('settings/tenants/{tenant}', [TenantController::class, 'edit'])->name('tenants.edit');
        Route::patch('settings/tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
        Route::delete('settings/tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');
        Route::post('settings/tenants/{tenant}/switch', [TenantController::class, 'switch'])->name('tenants.switch');
        Route::delete('settings/tenants/{tenant}/leave', [TenantController::class, 'leave'])->name('tenants.leave');

        Route::patch('settings/tenants/{tenant}/members/{user}', [TenantMemberController::class, 'update'])->name('tenants.members.update');
        Route::delete('settings/tenants/{tenant}/members/{user}', [TenantMemberController::class, 'destroy'])->name('tenants.members.destroy');

        Route::post('settings/tenants/{tenant}/invitations', [TenantInvitationController::class, 'store'])->name('tenants.invitations.store');
        Route::delete('settings/tenants/{tenant}/invitations/{invitation}', [TenantInvitationController::class, 'destroy'])->name('tenants.invitations.destroy');
    });
});
