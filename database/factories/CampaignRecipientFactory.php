<?php

namespace Database\Factories;

use App\Models\CampaignRecipient;
use Database\Factories\Concerns\GeneratesMetaIds;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignRecipient>
 */
class CampaignRecipientFactory extends Factory
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
            'campaign_id' => CampaignFactory::new(),
            'contact_id' => ContactFactory::new(),
            'message_id' => null,
            'wamid' => null,
            'status' => 'pending',
            'suppression_reason' => null,
            'error_code' => null,
            'cost_minor' => null,
            'variables' => ['1' => fake()->firstName()],
            'queued_at' => null,
            'sent_at' => null,
            'delivered_at' => null,
            'read_at' => null,
            'clicked_at' => null,
            'replied_at' => null,
            'failed_at' => null,
        ];
    }

    /**
     * Indicate that the recipient was suppressed pre-send.
     */
    public function suppressed(string $reason = 'no_consent'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suppressed',
            'suppression_reason' => $reason,
        ]);
    }

    /**
     * Indicate that the message was delivered to the recipient.
     */
    public function delivered(): static
    {
        return $this->state(function (array $attributes) {
            $queuedAt = now()->subHours(2);

            return [
                'status' => 'delivered',
                'wamid' => $this->wamid(),
                'cost_minor' => 420,
                'queued_at' => $queuedAt,
                'sent_at' => $queuedAt->copy()->addSeconds(4),
                'delivered_at' => $queuedAt->copy()->addSeconds(11),
            ];
        });
    }
}
