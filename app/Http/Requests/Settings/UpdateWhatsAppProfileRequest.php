<?php

namespace App\Http\Requests\Settings;

use App\Enums\WhatsAppVertical;
use App\Support\Facades\Teams;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Mirrors every limit Meta enforces on
 * `POST /{phone-number-id}/whatsapp_business_profile`
 * (docs/reference/whatsapp-cloud-api.md §5), so the client gets a 422 with a
 * field-level message instead of a Graph error rendered as a banner.
 */
class UpdateWhatsAppProfileRequest extends FormRequest
{
    /**
     * Only owners and admins may edit the connection.
     */
    public function authorize(): bool
    {
        return Gate::allows('manageWhatsApp', Teams::currentOrFail());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Meta rejects an empty `about`; a blank one is simply not sent.
            'about' => ['nullable', 'string', 'max:139'],
            'address' => ['nullable', 'string', 'max:256'],
            'email' => ['nullable', 'email', 'max:128'],
            'description' => ['nullable', 'string', 'max:512'],
            'vertical' => ['nullable', Rule::enum(WhatsAppVertical::class)],
            // Two is a hard Meta limit, not a UI choice.
            'websites' => ['nullable', 'array', 'max:2'],
            // `url:http,https` is what enforces the required scheme —
            // "example.com" is rejected, "https://example.com" is not.
            'websites.*' => ['nullable', 'string', 'max:256', 'url:http,https'],
            'profile_picture' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'websites.max' => __('WhatsApp accepts at most two websites.'),
            'websites.*.url' => __('Each website must start with http:// or https://.'),
            'profile_picture.mimes' => __('The profile picture must be a JPG or PNG file.'),
            'profile_picture.max' => __('The profile picture may not be larger than 5 MB.'),
        ];
    }

    /**
     * Blank inputs arrive as empty strings from the form; normalise them to
     * null so `nullable` applies and Meta receives a deliberate clear.
     */
    protected function prepareForValidation(): void
    {
        $websites = $this->input('websites');

        $this->merge([
            'websites' => is_array($websites)
                ? array_values(array_filter(
                    array_map(fn (mixed $website): string => trim((string) $website), $websites),
                    fn (string $website): bool => $website !== '',
                ))
                : [],
        ]);

        foreach (['about', 'address', 'email', 'description', 'vertical'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }
}
