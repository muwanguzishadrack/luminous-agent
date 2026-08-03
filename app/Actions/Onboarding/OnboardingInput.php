<?php

namespace App\Actions\Onboarding;

/**
 * Per-run inputs for the onboarding chain. Both values are secrets supplied
 * by the browser for a single run — they are **never** persisted or logged.
 */
final readonly class OnboardingInput
{
    public function __construct(
        /** One-time Embedded Signup code (ExchangeSignupCode only). */
        public ?string $code = null,
        /**
         * The number's EXISTING two-step-verification PIN, when it already has
         * one. Meta requires the current PIN on register; a generated one is
         * only correct for a number without 2FA (error 133005 otherwise).
         */
        public ?string $pin = null,
    ) {}
}
