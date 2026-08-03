<?php

namespace Database\Factories;

use App\Enums\ConsentScope;
use App\Enums\ConsentSource;
use App\Enums\ConsentState;
use App\Models\Consent;
use Database\Factories\Concerns\GeneratesMetaIds;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consent>
 */
class ConsentFactory extends Factory
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
            'contact_id' => ContactFactory::new(),
            'scope' => ConsentScope::Marketing,
            'state' => ConsentState::Granted,
            'source' => ConsentSource::InboundKeyword,
            'evidence' => ['wamid' => $this->wamid(), 'keyword' => 'START'],
            'occurred_at' => fake()->dateTimeBetween('-90 days'),
        ];
    }

    /**
     * Indicate that the consent was revoked natively in WhatsApp — the
     * source that always wins over any other.
     */
    public function nativeRevoke(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => ConsentState::Revoked,
            'source' => ConsentSource::WhatsappNative,
            'evidence' => ['event' => 'user_preferences', 'value' => 'stop'],
        ]);
    }

    /**
     * Indicate that the consent was granted via a vetted list import.
     */
    public function imported(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => ConsentSource::Import,
            'evidence' => ['uploader' => fake()->safeEmail(), 'file' => 'contacts-import.csv'],
        ]);
    }
}
