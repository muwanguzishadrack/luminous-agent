<?php

namespace Database\Factories;

use App\Enums\MetaCredentialType;
use App\Models\MetaCredential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MetaCredential>
 */
class MetaCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = 'EAAG'.Str::random(160);

        return [
            'waba_account_id' => WabaAccountFactory::new(),
            'type' => MetaCredentialType::Business,
            'token' => $token,
            'token_last4' => substr($token, -4),
            'scopes' => ['whatsapp_business_messaging', 'whatsapp_business_management', 'business_management'],
            'issued_at' => now()->subDays(90),
            'expires_at' => null,
            'revoked_at' => null,
            'last_used_at' => now()->subMinutes(5),
            'failure_count' => 0,
        ];
    }

    /**
     * Indicate that this is a Meta Business Agent (BiSU) token.
     */
    public function bisu(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MetaCredentialType::Bisu,
        ]);
    }

    /**
     * Indicate that the credential has been revoked.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }
}
