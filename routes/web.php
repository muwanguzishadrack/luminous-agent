<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CommerceController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GrowthController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\Onboarding\OnboardingPageController;
use App\Http\Controllers\SegmentController;
use App\Http\Controllers\SequenceController;
use App\Http\Controllers\Settings\WhatsAppController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\TemplateController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::prefix('{team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('inbox', InboxController::class)->name('inbox');
        Route::get('contacts', ContactController::class)->name('contacts');
        Route::get('segments', SegmentController::class)->name('segments');
        Route::get('templates', TemplateController::class)->name('templates');
        Route::get('campaigns', CampaignController::class)->name('campaigns');
        Route::get('sequences', SequenceController::class)->name('sequences');
        Route::get('agent', AgentController::class)->name('agent');
        Route::get('commerce', CommerceController::class)->name('commerce');
        Route::get('growth', GrowthController::class)->name('growth');
        Route::get('analytics', AnalyticsController::class)->name('analytics');
    });

// The invitee's own path. Public because they have no account yet — the
// 64-character invitation code is the credential, and the throttle keeps it
// from being guessed at.
Route::middleware('throttle:6,1')->group(function () {
    Route::get('invitations/{invitation}/join', [TeamInvitationController::class, 'join'])->name('invitations.join');
    Route::post('invitations/{invitation}/join', [TeamInvitationController::class, 'storeMember'])->name('invitations.join.store');
});

Route::middleware(['auth'])->group(function () {
    // Not team-prefixed: an invitee has no team to prefix it with (D-020).
    Route::get('invitations', [TeamInvitationController::class, 'index'])->name('invitations.index');
    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');

    // Embedded Signup v4 (docs/modules/m0-onboarding.md §1, §7): the
    // launcher page, the team's single WhatsApp connection, and the server
    // chain endpoints.
    Route::get('onboarding', OnboardingPageController::class)->name('onboarding.index');
    Route::get('settings/whatsapp', [WhatsAppController::class, 'show'])->name('whatsapp.show');

    // Writes against the live connection: a team is required, so the
    // membership middleware refuses (403) rather than the RLS scope silently
    // matching nothing.
    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::post('settings/whatsapp/refresh', [WhatsAppController::class, 'refresh'])->name('whatsapp.refresh');
        Route::post('settings/whatsapp/profile', [WhatsAppController::class, 'updateProfile'])->name('whatsapp.profile.update');
        Route::delete('settings/whatsapp', [WhatsAppController::class, 'disconnect'])->name('whatsapp.disconnect');
    });

    Route::post('onboarding/start', [OnboardingController::class, 'start'])->name('onboarding.start');
    Route::post('onboarding/events', [OnboardingController::class, 'events'])->name('onboarding.events');
    Route::post('onboarding/exchange', [OnboardingController::class, 'exchange'])->name('onboarding.exchange');
    Route::post('onboarding/resume/{session}', [OnboardingController::class, 'resume'])->name('onboarding.resume');
});

require __DIR__.'/settings.php';
