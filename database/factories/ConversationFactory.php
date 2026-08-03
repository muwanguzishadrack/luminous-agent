<?php

namespace Database\Factories;

use App\Enums\ConversationState;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lastInbound = fake()->dateTimeBetween('-7 days');
        $lastOutbound = fake()->dateTimeBetween($lastInbound);

        return [
            'phone_number_id' => PhoneNumberFactory::new(),
            'contact_id' => ContactFactory::new(),
            'state' => ConversationState::Human,
            'owner_app_id' => null,
            'assigned_user_id' => null,
            'assigned_at' => null,
            'csw_expires_at' => Carbon::instance($lastInbound)->addDay(),
            'fep_expires_at' => null,
            'last_message_at' => $lastOutbound,
            'last_inbound_at' => $lastInbound,
            'last_outbound_at' => $lastOutbound,
            'unread_count' => 0,
            'first_response_at' => $lastOutbound,
            'resolved_at' => null,
            'snoozed_until' => null,
            'sla_breached_at' => null,
            'ai_handled_count' => 0,
            'human_handled_count' => 1,
        ];
    }

    /**
     * Indicate that the AI currently owns the conversation.
     */
    public function ai(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => ConversationState::Ai,
            'ai_handled_count' => 1,
            'human_handled_count' => 0,
        ]);
    }

    /**
     * Indicate that the conversation is waiting for a human agent.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => ConversationState::Queued,
            'unread_count' => fake()->numberBetween(1, 5),
            'first_response_at' => null,
        ]);
    }

    /**
     * Indicate that the conversation has been resolved and closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => ConversationState::Closed,
            'resolved_at' => now()->subHours(2),
        ]);
    }
}
