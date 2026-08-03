<?php

namespace Database\Factories;

use App\Enums\ConsentScope;
use App\Enums\ConsentSource;
use App\Enums\ConsentState as ConsentStateEnum;
use App\Models\ConsentState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentState>
 */
class ConsentStateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => ContactFactory::new(),
            'scope' => ConsentScope::Marketing,
            'state' => ConsentStateEnum::Granted,
            'source' => ConsentSource::InboundKeyword,
            'occurred_at' => fake()->dateTimeBetween('-90 days'),
            'consent_id' => ConsentFactory::new(),
        ];
    }

    /**
     * Indicate that the current state is revoked.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => ConsentStateEnum::Revoked,
            'source' => ConsentSource::WhatsappNative,
        ]);
    }
}
