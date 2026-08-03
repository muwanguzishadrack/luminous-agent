<?php

namespace Database\Factories;

use App\Models\Contact;
use Database\Factories\Concerns\GeneratesMetaIds;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    use GeneratesMetaIds;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $waId = $this->waId();
        $firstSeen = fake()->dateTimeBetween('-120 days', '-2 days');
        $hasInbound = fake()->boolean(85);
        $lastInbound = $hasInbound ? fake()->dateTimeBetween($firstSeen) : null;

        return [
            'wa_id' => $waId,
            'phone_e164' => '+'.$waId,
            'profile_name' => fake()->firstName(),
            'display_name' => fake()->name(),
            'locale' => fake()->randomElement(['en', 'en', 'en', 'en', 'lg', 'sw']),
            'lifecycle_stage' => fake()->randomElement(['lead', 'lead', 'engaged', 'engaged', 'customer', 'churned']),
            'owner_id' => null,
            'source' => fake()->randomElement(['inbound', 'inbound', 'ctwa', 'import', 'coexistence', 'qr']),
            'first_seen_at' => $firstSeen,
            'last_inbound_at' => $lastInbound,
            'last_outbound_at' => $lastInbound !== null ? fake()->dateTimeBetween($lastInbound) : null,
            'lifetime_value' => 0,
            'orders_count' => 0,
            'is_blocked' => false,
            'undeliverable_at' => null,
            'custom_fields' => (object) [],
        ];
    }

    /**
     * Indicate that the contact cannot receive messages (send error 131026).
     */
    public function undeliverable(): static
    {
        return $this->state(fn (array $attributes) => [
            'undeliverable_at' => now()->subDays(3),
        ]);
    }
}
