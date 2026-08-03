<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use Database\Factories\Concerns\GeneratesMetaIds;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    use GeneratesMetaIds;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $items = collect(range(1, fake()->numberBetween(1, 3)))->map(fn () => [
            'retailer_id' => 'SKU-'.fake()->numerify('####'),
            'name' => fake()->words(3, true),
            'qty' => fake()->numberBetween(1, 4),
            'unit_price_minor' => fake()->numberBetween(5, 150) * 1000,
            'currency' => 'UGX',
        ])->all();

        $subtotal = array_sum(array_map(
            fn (array $item): int => $item['qty'] * $item['unit_price_minor'],
            $items,
        ));
        $shipping = 5000;

        return [
            'contact_id' => ContactFactory::new(),
            'conversation_id' => null,
            'reference' => 'ORD-'.fake()->unique()->numerify('2026-#####'),
            'source' => fake()->randomElement(['whatsapp_cart', 'whatsapp_cart', 'agent', 'mba']),
            'origin_wamid' => $this->wamid(),
            'items' => $items,
            'subtotal_minor' => $subtotal,
            'shipping_minor' => $shipping,
            'discount_minor' => 0,
            'total_minor' => $subtotal + $shipping,
            'currency' => 'UGX',
            'status' => OrderStatus::PendingPayment,
            'paid_at' => null,
            'cancelled_at' => null,
            'notes' => null,
            'meta' => (object) [],
        ];
    }

    /**
     * Indicate that the order has been fully paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Paid,
            'paid_at' => now()->subHours(fake()->numberBetween(1, 72)),
        ]);
    }
}
