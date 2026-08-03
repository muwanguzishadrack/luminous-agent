<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\MessageStatus;
use App\Models\Message;
use Database\Factories\Concerns\GeneratesMetaIds;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    use GeneratesMetaIds;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $body = fake()->sentence();

        return [
            'conversation_id' => ConversationFactory::new(),
            'wamid' => $this->wamid(),
            'direction' => MessageDirection::Inbound,
            'type' => 'text',
            'body' => $body,
            'payload' => ['text' => ['body' => $body]],
            'media_id' => null,
            'replied_to_wamid' => null,
            'reaction_to_wamid' => null,
            'origin' => MessageOrigin::Customer,
            'sent_by_user_id' => null,
            'campaign_id' => null,
            'template_id' => null,
            'status' => MessageStatus::Read,
            'error_code' => null,
            'error_detail' => null,
            'pricing_category' => null,
            'billable' => null,
            'cost_minor' => null,
            'token_count' => null,
            'sent_at' => null,
            'delivered_at' => null,
            'read_at' => null,
            'failed_at' => null,
            'occurred_at' => fake()->dateTimeBetween('-7 days'),
        ];
    }

    /**
     * Indicate that the message was sent by an agent within the service window.
     */
    public function outbound(): static
    {
        return $this->state(function (array $attributes) {
            $sentAt = fake()->dateTimeBetween('-7 days');

            return [
                'direction' => MessageDirection::Outbound,
                'origin' => MessageOrigin::Agent,
                'status' => MessageStatus::Delivered,
                'pricing_category' => 'service',
                'billable' => false,
                'sent_at' => $sentAt,
                'delivered_at' => Carbon::instance($sentAt)->addSeconds(20),
                'occurred_at' => $sentAt,
            ];
        });
    }

    /**
     * Indicate that the message failed to send.
     */
    public function failed(): static
    {
        return $this->outbound()->state(fn (array $attributes) => [
            'status' => MessageStatus::Failed,
            'error_code' => 131026,
            'error_detail' => ['title' => 'Message undeliverable'],
            'delivered_at' => null,
            'failed_at' => $attributes['sent_at'] ?? now(),
        ]);
    }
}
