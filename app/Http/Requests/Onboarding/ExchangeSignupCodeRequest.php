<?php

namespace App\Http\Requests\Onboarding;

use App\Actions\Onboarding\OnboardingStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The ES FINISH payload (docs/modules/m0-onboarding.md §1). The `code` is a
 * one-time secret: it is exchanged and dropped, never persisted or logged.
 */
class ExchangeSignupCodeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nonce' => ['required', 'string'],
            'code' => ['required', 'string'],
            'waba_id' => ['required', 'string', 'max:64'],
            'phone_number_id' => ['required', 'string', 'max:64'],
            'feature_type' => ['nullable', 'string', Rule::in([OnboardingStatus::COEXISTENCE_FEATURE])],
            // Only supplied when the number already has two-step verification.
            'pin' => ['nullable', 'digits:6'],
        ];
    }
}
