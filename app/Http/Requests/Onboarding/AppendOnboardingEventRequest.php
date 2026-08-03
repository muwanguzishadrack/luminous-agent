<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A WA_EMBEDDED_SIGNUP session event from the JS SDK message listener —
 * appended verbatim to onboarding_sessions.events (docs/m0 §1).
 */
class AppendOnboardingEventRequest extends FormRequest
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
            'event' => ['required', 'array'],
        ];
    }
}
