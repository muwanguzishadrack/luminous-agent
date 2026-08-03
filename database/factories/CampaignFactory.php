<?php

namespace Database\Factories;

use App\Enums\CampaignRouting;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone_number_id' => PhoneNumberFactory::new(),
            'name' => ucfirst(fake()->word()).' '.fake()->word().' campaign',
            'template_id' => TemplateFactory::new(),
            'template_group_id' => null,
            'segment_id' => null,
            'routing' => CampaignRouting::CloudApi,
            'product_policy' => null,
            'status' => CampaignStatus::Draft,
            'scheduled_for' => null,
            'timezone_mode' => 'fixed',
            'budget_cap_minor' => null,
            'spent_minor' => 0,
            'variant_group_id' => null,
            'variant_weight' => null,
            'stats' => (object) [],
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    /**
     * Indicate that the campaign is mid-send.
     */
    public function sending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CampaignStatus::Sending,
            'started_at' => now()->subMinutes(20),
        ]);
    }

    /**
     * Indicate that the campaign finished sending.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CampaignStatus::Completed,
            'started_at' => now()->subDays(6),
            'completed_at' => now()->subDays(6)->addHours(1),
        ]);
    }
}
