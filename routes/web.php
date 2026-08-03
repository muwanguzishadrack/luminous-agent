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
use App\Http\Controllers\SegmentController;
use App\Http\Controllers\SequenceController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\Tenants\TenantInvitationController;
use App\Http\Middleware\EnsureTenantMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::prefix('{current_tenant}')
    ->middleware(['auth', 'verified', EnsureTenantMembership::class])
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

Route::middleware(['auth'])->group(function () {
    Route::get('invitations/{invitation}/accept', [TenantInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TenantInvitationController::class, 'decline'])->name('invitations.decline');

    // Embedded Signup v4 server chain (docs/modules/m0-onboarding.md §1).
    Route::post('onboarding/start', [OnboardingController::class, 'start'])->name('onboarding.start');
    Route::post('onboarding/events', [OnboardingController::class, 'events'])->name('onboarding.events');
    Route::post('onboarding/exchange', [OnboardingController::class, 'exchange'])->name('onboarding.exchange');
    Route::post('onboarding/resume/{session}', [OnboardingController::class, 'resume'])->name('onboarding.resume');
});

require __DIR__.'/settings.php';
