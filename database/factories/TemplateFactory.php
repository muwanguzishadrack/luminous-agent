<?php

namespace Database\Factories;

use App\Enums\TemplateCategory;
use App\Models\Template;
use Database\Factories\Concerns\GeneratesMetaIds;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
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
            'template_group_id' => null,
            'meta_template_id' => $this->metaId(),
            'name' => Str::snake(fake()->unique()->word().' '.fake()->word().' '.fake()->word()),
            'language' => 'en',
            'category' => TemplateCategory::Marketing,
            'sub_type' => null,
            'status' => 'APPROVED',
            'quality_score' => 'GREEN',
            'rejected_reason' => null,
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => 'Hi {{1}}, '.fake()->sentence(8),
                    'example' => ['body_text' => [['Aisha']]],
                ],
                ['type' => 'FOOTER', 'text' => 'Reply STOP to opt out'],
            ],
            'variable_map' => [
                'body' => ['1' => ['field' => 'contact.display_name', 'fallback' => 'there']],
            ],
            'ttl_seconds' => null,
            'library_template_name' => null,
            'paused_until' => null,
            'last_synced_at' => now()->subHours(6),
        ];
    }

    /**
     * Indicate that the template has not been submitted to Meta yet.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'DRAFT',
            'meta_template_id' => null,
            'quality_score' => null,
            'last_synced_at' => null,
        ]);
    }

    /**
     * Indicate that the template is awaiting Meta review.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'PENDING',
            'quality_score' => null,
        ]);
    }

    /**
     * Indicate that Meta rejected the template.
     */
    public function rejected(string $reason = 'INVALID_FORMAT'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'REJECTED',
            'quality_score' => null,
            'rejected_reason' => $reason,
        ]);
    }

    /**
     * Indicate that Meta paused the template for low quality.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'PAUSED',
            'quality_score' => 'RED',
            'paused_until' => now()->addDays(3),
        ]);
    }

    /**
     * Indicate that Meta disabled the template after repeated pauses.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'DISABLED',
            'quality_score' => 'RED',
        ]);
    }

    /**
     * Indicate that a rejection is being appealed.
     */
    public function inAppeal(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'IN_APPEAL',
            'quality_score' => null,
            'rejected_reason' => 'PROMOTION_NOT_ALLOWED',
        ]);
    }

    /**
     * Indicate that the template is a UTILITY template.
     */
    public function utility(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => TemplateCategory::Utility,
        ]);
    }

    /**
     * Indicate that the template is a one-time-password AUTHENTICATION template.
     */
    public function authentication(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => TemplateCategory::Authentication,
            'sub_type' => 'otp',
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => '{{1}} is your verification code. For your security, do not share this code.',
                    'add_security_recommendation' => true,
                ],
                ['type' => 'FOOTER', 'code_expiration_minutes' => 10],
                [
                    'type' => 'BUTTONS',
                    'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copy code']],
                ],
            ],
            'variable_map' => ['body' => ['1' => ['field' => 'otp.code', 'fallback' => null]]],
        ]);
    }
}
