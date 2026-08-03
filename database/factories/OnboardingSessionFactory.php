<?php

namespace Database\Factories;

use App\Models\OnboardingSession;
use Database\Factories\Concerns\GeneratesMetaIds;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OnboardingSession>
 */
class OnboardingSessionFactory extends Factory
{
    use GeneratesMetaIds;

    protected $model = OnboardingSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'nonce' => Str::random(40),
            'feature_type' => null,
            'es_version' => 'v4',
            'events' => [],
            'waba_id' => null,
            'phone_number_id' => null,
            'code_exchanged_at' => null,
            'history_sync_requested_at' => null,
            'history_sync_completed_at' => null,
            'contacts_sync_requested_at' => null,
            'status' => 'started',
            'failure' => null,
        ];
    }

    /**
     * The ES window reached FINISH: asset ids captured, code not yet exchanged.
     */
    public function finished(): static
    {
        return $this->state(fn (array $attributes) => [
            'waba_id' => $this->metaId(),
            'phone_number_id' => $this->metaId(),
            'status' => 'finished',
        ]);
    }

    /**
     * The code exchange succeeded — a business token is vaulted.
     */
    public function exchanged(): static
    {
        return $this->finished()->state(fn (array $attributes) => [
            'code_exchanged_at' => now(),
            'status' => 'exchanged',
        ]);
    }

    /**
     * A Coexistence onboarding (docs/modules/m0-onboarding.md §3).
     */
    public function coexistence(): static
    {
        return $this->state(fn (array $attributes) => [
            'feature_type' => 'whatsapp_business_app_onboarding',
        ]);
    }
}
