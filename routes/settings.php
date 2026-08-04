<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Middleware\EnsureTeamMembership;
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

    // Singular: a user has at most one team (D-020), so the team-settings
    // screen names no team — the membership resolves it.
    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::get('settings/team', [TeamController::class, 'edit'])->name('team.edit');
        Route::patch('settings/team', [TeamController::class, 'update'])->name('team.update');
        Route::delete('settings/team', [TeamController::class, 'destroy'])->name('team.destroy');

        Route::patch('settings/team/members/{user}', [TeamMemberController::class, 'update'])->name('team.members.update');
        Route::delete('settings/team/members/{user}', [TeamMemberController::class, 'destroy'])->name('team.members.destroy');

        Route::post('settings/team/invitations', [TeamInvitationController::class, 'store'])->name('team.invitations.store');
        Route::delete('settings/team/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('team.invitations.destroy');
    });
});
