<?php

namespace Database\Factories;

use App\Models\PhoneNumber;
use Database\Factories\Concerns\GeneratesMetaIds;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhoneNumber>
 */
class PhoneNumberFactory extends Factory
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
            'waba_account_id' => WabaAccountFactory::new(),
            'phone_number_id' => $this->metaId(),
            'display_phone_number' => '+256 '.fake()->numerify('7## ### ###'),
            'verified_name' => fake()->company(),
            'code_verification_status' => 'VERIFIED',
            'quality_rating' => 'GREEN',
            'connection_status' => 'CONNECTED',
            'throughput_level' => 'STANDARD',
            'platform_type' => 'CLOUD_API',
            'is_on_biz_app' => false,
            'is_official_business_account' => false,
            'registered_at' => now()->subDays(85),
            'pin_set' => true,
            'profile' => [
                'about' => 'Talk to us on WhatsApp — we reply fast.',
                'address' => 'Kampala, Uganda',
                'description' => fake()->sentence(6),
                'email' => fake()->companyEmail(),
                'websites' => ['https://'.fake()->domainName()],
                'vertical' => 'RETAIL',
            ],
            'status' => 'active',
        ];
    }

    /**
     * Indicate that this is a Coexistence number (still linked to the
     * WhatsApp Business App). Throughput is fixed at 20 mps for Coexistence
     * numbers — the sender derives that cap from is_on_biz_app, while Meta
     * keeps reporting the level as STANDARD (docs/reference/pricing-and-limits.md §3).
     */
    public function coexistence(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_on_biz_app' => true,
            'platform_type' => 'CLOUD_API',
            'throughput_level' => 'STANDARD',
        ]);
    }
}
