<?php

namespace Database\Factories;

use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->numberBetween(5, 500) * 1000;

        return [
            'order_id' => null,
            'contact_id' => null,
            'direction' => PaymentDirection::Collection,
            'provider' => 'iotec',
            'external_id' => (string) Str::ulid(),
            'provider_id' => (string) Str::uuid(),
            'vendor_transaction_id' => null,
            'category' => 'MobileMoney',
            'wallet_id' => IotecWalletFactory::new(),
            'currency' => 'UGX',
            'amount_minor' => $amount,
            'payer' => '2567'.fake()->numerify('########'),
            'payer_name' => fake()->name(),
            'payee' => null,
            'payee_name' => null,
            'status' => PaymentStatus::Pending,
            'status_code' => null,
            'status_message' => null,
            'vendor' => fake()->randomElement(['Mtn', 'Airtel']),
            'payment_channel' => 'Api',
            'transaction_charge_minor' => null,
            'vendor_charge_minor' => null,
            'total_charge_minor' => null,
            'card_redirect_url' => null,
            'redirect_url' => null,
            'requested_at' => now()->subMinutes(fake()->numberBetween(2, 600)),
            'processed_at' => null,
            'last_polled_at' => null,
            'raw' => (object) [],
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /**
     * Indicate that the vendor confirmed the payment.
     */
    public function success(): static
    {
        return $this->state(function (array $attributes) {
            $amount = $attributes['amount_minor'];
            $charge = (int) round($amount * 0.03);

            return [
                'status' => PaymentStatus::Success,
                'status_code' => '0',
                'status_message' => 'Transaction completed successfully',
                'vendor_transaction_id' => strtoupper(Str::random(12)),
                'transaction_charge_minor' => $charge,
                'vendor_charge_minor' => 0,
                'total_charge_minor' => $charge,
                'processed_at' => now()->subMinutes(fake()->numberBetween(1, 60)),
            ];
        });
    }

    /**
     * Indicate that the payment is an outbound disbursement.
     */
    public function disbursement(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => PaymentDirection::Disbursement,
            'payer' => null,
            'payer_name' => null,
            'payee' => '2567'.fake()->numerify('########'),
            'payee_name' => fake()->name(),
        ]);
    }
}
