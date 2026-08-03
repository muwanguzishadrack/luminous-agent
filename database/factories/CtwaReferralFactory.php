<?php

namespace Database\Factories;

use App\Models\CtwaReferral;
use Database\Factories\Concerns\GeneratesMetaIds;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CtwaReferral>
 */
class CtwaReferralFactory extends Factory
{
    use GeneratesMetaIds;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $adId = $this->metaId();

        return [
            'contact_id' => ContactFactory::new(),
            'conversation_id' => ConversationFactory::new(),
            'message_wamid' => $this->wamid(),
            'source_id' => $adId,
            'source_type' => 'ad',
            'source_url' => 'https://fb.me/'.substr($adId, 0, 10),
            'headline' => fake()->sentence(4),
            'body' => fake()->sentence(10),
            'media_type' => 'image',
            'image_url' => 'https://scontent.xx.fbcdn.net/v/'.fake()->numerify('t45.####').'/ad_creative.jpg',
            'video_url' => null,
            'thumbnail_url' => null,
            'ctwa_clid' => $this->ctwaClid(),
            'welcome_message' => ['text' => 'Hi! I saw your ad and I would like to know more.'],
            'occurred_at' => fake()->dateTimeBetween('-3 days'),
        ];
    }
}
