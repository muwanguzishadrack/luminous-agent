<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\PaymentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PaymentEvent>
 */
class PaymentEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $occurredAt = fake()->dateTimeBetween('-3 days');

        return [
            'payment_id' => PaymentFactory::new(),
            'status' => PaymentStatus::Pending,
            'status_code' => null,
            'status_message' => 'Transaction pending',
            'source' => 'callback',
            'raw' => ['status' => PaymentStatus::Pending->value],
            'occurred_at' => $occurredAt,
            'received_at' => Carbon::instance($occurredAt)->addSeconds(2),
        ];
    }

    /**
     * Indicate that the event was discovered by the status poller.
     */
    public function polled(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'poll',
        ]);
    }
}
