<?php

namespace App\Actions\WhatsApp;

use App\Models\PhoneNumber;
use App\Services\Meta\CredentialResolver;

/**
 * Reads `GET /{phone-number-id}/whatsapp_business_profile` and mirrors it into
 * `phone_numbers.profile`, so the settings screen renders from local state and
 * a page load never depends on Graph being up
 * (docs/reference/whatsapp-cloud-api.md §5).
 */
class ReadBusinessProfile
{
    /** The readable profile fields, in the order the form renders them. */
    public const FIELDS = ['about', 'address', 'email', 'description', 'vertical', 'websites', 'profile_picture_url'];

    public function __construct(private readonly CredentialResolver $credentials) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(PhoneNumber $number): array
    {
        return $this->persist(
            $number,
            $this->credentials->businessClient()->businessProfile($number->phone_number_id),
        );
    }

    /**
     * Meta wraps the single profile in a `data` list; both shapes are accepted
     * so a bare object does not silently read as empty.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    public function persist(PhoneNumber $number, array $response): array
    {
        /** @var array<string, mixed> $raw */
        $raw = isset($response['data'][0]) ? (array) $response['data'][0] : $response;

        $websites = array_values(array_map(
            fn (mixed $website): string => (string) $website,
            (array) ($raw['websites'] ?? []),
        ));

        $profile = [
            'about' => isset($raw['about']) ? (string) $raw['about'] : null,
            'address' => isset($raw['address']) ? (string) $raw['address'] : null,
            'email' => isset($raw['email']) ? (string) $raw['email'] : null,
            'description' => isset($raw['description']) ? (string) $raw['description'] : null,
            'vertical' => isset($raw['vertical']) && $raw['vertical'] !== '' ? (string) $raw['vertical'] : null,
            // Meta caps websites at two; anything beyond is Meta's problem, not
            // something we render.
            'websites' => array_slice($websites, 0, 2),
            // Read-only counterpart of the write-only profile_picture_handle.
            'profile_picture_url' => isset($raw['profile_picture_url']) ? (string) $raw['profile_picture_url'] : null,
        ];

        $number->forceFill(['profile' => $profile])->save();

        return $profile;
    }
}
