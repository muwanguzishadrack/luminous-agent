<?php

namespace Database\Factories;

use App\Models\WabaAccount;
use Database\Factories\Concerns\GeneratesMetaIds;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WabaAccount>
 */
class WabaAccountFactory extends Factory
{
    use GeneratesMetaIds;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'waba_id' => $this->metaId(),
            'owner_business_id' => $this->metaId(),
            'solution_id' => null,
            'name' => fake()->company(),
            'timezone_id' => 'Africa/Kampala',
            'currency' => 'USD',
            'review_status' => 'APPROVED',
            'account_status' => 'ACTIVE',
            'business_verification_status' => 'verified',
            'portfolio_messaging_limit' => '10000',
            'is_subscribed' => true,
            'payment_ready' => true,
            'onboarded_at' => now()->subDays(90),
            'offboarded_at' => null,
        ];
    }

    /**
     * Indicate that the WABA was onboarded via a Multi-Partner Solution.
     */
    public function multiPartner(): static
    {
        return $this->state(fn (array $attributes) => [
            'solution_id' => $this->metaId(),
        ]);
    }
}
