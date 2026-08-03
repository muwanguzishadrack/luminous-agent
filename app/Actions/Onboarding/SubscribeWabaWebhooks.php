<?php

namespace App\Actions\Onboarding;

use App\Models\OnboardingSession;
use App\Services\Meta\CredentialResolver;
use App\Support\AuditLog;
use RuntimeException;

/**
 * Step 3 — subscribe our app to the WABA's webhooks, then read the edge
 * back and assert our app id is present (docs/modules/m0-onboarding.md §1
 * step 3). A silent subscription miss loses webhooks unrecoverably, so a
 * failed verification fails the step loudly.
 */
class SubscribeWabaWebhooks extends OnboardingStep
{
    public function __construct(private readonly CredentialResolver $credentials) {}

    public function runsAt(): string
    {
        return OnboardingStatus::REGISTERED;
    }

    public function handle(OnboardingSession $session, ?string $code = null): void
    {
        $this->assertFinishPayload($session);

        $client = $this->credentials->businessClient();

        $client->post("{$session->waba_id}/subscribed_apps");

        $appId = (string) config('meta.app_id');
        $apps = $client->get("{$session->waba_id}/subscribed_apps");

        if (! $this->containsApp((array) ($apps['data'] ?? []), $appId)) {
            throw new RuntimeException(
                "App {$appId} is absent from {$session->waba_id}/subscribed_apps after subscribing — webhooks would be lost.",
            );
        }

        $this->advance($session, OnboardingStatus::SYNCING);

        AuditLog::record('onboarding.waba_subscribed', subject: $session, context: [
            'waba_id' => $session->waba_id,
            'app_id' => $appId,
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $apps
     */
    private function containsApp(array $apps, string $appId): bool
    {
        foreach ($apps as $app) {
            $app = (array) $app;
            $whatsappData = (array) ($app['whatsapp_business_api_data'] ?? []);

            if ((string) ($whatsappData['id'] ?? $app['id'] ?? '') === $appId) {
                return true;
            }
        }

        return false;
    }
}
