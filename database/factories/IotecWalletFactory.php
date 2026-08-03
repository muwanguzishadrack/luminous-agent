<?php

namespace Database\Factories;

use App\Models\IotecWallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IotecWallet>
 */
class IotecWalletFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'iotec_wallet_id' => (string) Str::uuid(),
            'name' => fake()->company().' Collections',
            'currency' => 'UGX',
            'actual_balance_minor' => fake()->numberBetween(100, 5_000) * 1000,
            'available_balance_minor' => fake()->numberBetween(50, 4_000) * 1000,
            'collection_callback_url' => 'https://app.luminouscrm.test/webhooks/iotec/collections',
            'disbursement_callback_url' => 'https://app.luminouscrm.test/webhooks/iotec/disbursements',
            'callback_header_name' => null,
            'callback_header_value' => null,
            'last_synced_at' => now()->subMinutes(30),
        ];
    }
}
