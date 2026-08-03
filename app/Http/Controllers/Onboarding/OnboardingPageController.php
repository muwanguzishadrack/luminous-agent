<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\OnboardingStatus;
use App\Http\Controllers\Controller;
use App\Models\OnboardingSession;
use App\Support\Facades\Tenancy;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Connect WhatsApp screen (docs/modules/m0-onboarding.md §7): the
 * Embedded Signup v4 launcher with live, resumable step progress. Exposes
 * the public app id and ES config id only — never the app secret, and
 * never a token (docs/m0 §9 criterion 6).
 */
class OnboardingPageController extends Controller
{
    /**
     * Display the Embedded Signup launcher with the tenant's latest session.
     */
    public function __invoke(): Response
    {
        $session = $this->latestSession();

        return Inertia::render('onboarding/index', [
            'appId' => (string) config('meta.app_id'),
            'configId' => (string) config('meta.es_config_id'),
            'graphVersion' => (string) config('meta.graph_version'),
            'session' => $session === null ? null : [
                'id' => $session->id,
                'status' => $session->status,
                'featureType' => $session->feature_type,
                'failure' => $session->failure,
                // The nonce lets the browser resume an abandoned ES window
                // (docs/m0 §8) — only surfaced while that phase is pending.
                'nonce' => $session->status === OnboardingStatus::STARTED ? $session->nonce : null,
            ],
        ]);
    }

    /**
     * The tenant's most recent onboarding session. Ordered UUIDv7 primary
     * keys stand in for the created_at the table deliberately lacks.
     */
    private function latestSession(): ?OnboardingSession
    {
        $tenantId = Tenancy::currentId();

        if ($tenantId === null) {
            return null;
        }

        return OnboardingSession::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->first();
    }
}
