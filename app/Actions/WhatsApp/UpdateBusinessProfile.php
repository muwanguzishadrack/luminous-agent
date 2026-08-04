<?php

namespace App\Actions\WhatsApp;

use App\Enums\ActorType;
use App\Enums\MediaScanStatus;
use App\Models\Media;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\Meta\CredentialResolver;
use App\Services\Meta\GraphClient;
use App\Support\AuditLog;
use Illuminate\Http\UploadedFile;

/**
 * Writes the WhatsApp business profile
 * (`POST /{phone-number-id}/whatsapp_business_profile`).
 *
 * Two asymmetries drive the shape of this action
 * (docs/reference/whatsapp-cloud-api.md §5):
 *
 * 1. The picture is read as `profile_picture_url` but written as
 *    `profile_picture_handle`, and the handle only comes from the Resumable
 *    Upload API — so an upload is a server-side two-step, never a direct POST.
 * 2. The write answers `{"success": true}` and echoes nothing, so the profile
 *    is re-read afterwards and local state comes from Meta, not from the form.
 */
class UpdateBusinessProfile
{
    public function __construct(
        private readonly CredentialResolver $credentials,
        private readonly ReadBusinessProfile $read,
    ) {}

    /**
     * @param  array{about?: string|null, address?: string|null, description?: string|null, email?: string|null, vertical?: string|null, websites?: array<int, string>}  $fields
     * @return array<string, mixed>
     */
    public function handle(
        PhoneNumber $number,
        array $fields,
        ?UploadedFile $picture = null,
        ?User $actor = null,
    ): array {
        $client = $this->credentials->businessClient();

        $payload = $this->payload($fields);

        if ($picture !== null) {
            $payload['profile_picture_handle'] = $this->uploadPicture($client, $picture);
        }

        $client->updateBusinessProfile($number->phone_number_id, $payload);

        $profile = $this->read->persist(
            $number,
            $client->businessProfile($number->phone_number_id),
        );

        AuditLog::record(
            'whatsapp.business_profile_updated',
            $actor === null ? ActorType::System : ActorType::User,
            (string) $actor?->id,
            $number,
            [
                'phone_number_id' => $number->phone_number_id,
                // Field names only: the values are the client's own copy, and
                // the handle is a credential-shaped token we never persist.
                'fields' => array_values(array_diff(array_keys($payload), ['messaging_product'])),
                'picture_replaced' => $picture !== null,
            ],
        );

        return $profile;
    }

    /**
     * Meta clears a text field when it is sent empty, so a blanked input is
     * forwarded as `""` rather than dropped. `about` is the exception — Meta
     * rejects an empty `about`, so a blank one is omitted instead.
     *
     * @param  array{about?: string|null, address?: string|null, description?: string|null, email?: string|null, vertical?: string|null, websites?: array<int, string>}  $fields
     * @return array<string, mixed>
     */
    private function payload(array $fields): array
    {
        $payload = [];

        $about = trim((string) ($fields['about'] ?? ''));

        if ($about !== '') {
            $payload['about'] = $about;
        }

        foreach (['address', 'description', 'email', 'vertical'] as $key) {
            if (array_key_exists($key, $fields)) {
                $payload[$key] = (string) ($fields[$key] ?? '');
            }
        }

        if (array_key_exists('websites', $fields)) {
            $websites = array_values(array_filter(
                $fields['websites'],
                fn (string $website): bool => trim($website) !== '',
            ));

            // Hard Meta limit of two, enforced again here so a malformed call
            // cannot get past the form request.
            $payload['websites'] = array_slice($websites, 0, 2);
        }

        return $payload;
    }

    /**
     * Keep our own copy in the media library, then hand the bytes to the
     * Resumable Upload API for the handle Meta wants.
     */
    private function uploadPicture(GraphClient $client, UploadedFile $picture): string
    {
        $contents = (string) $picture->get();
        $mimeType = (string) ($picture->getMimeType() ?? 'application/octet-stream');
        $filename = $picture->getClientOriginalName();

        $disk = (string) config('filesystems.default');

        Media::query()->create([
            'sha256' => hash('sha256', $contents),
            'mime_type' => $mimeType,
            'size_bytes' => strlen($contents),
            'filename' => $filename,
            'disk' => $disk,
            'path' => (string) $picture->store('whatsapp/profile-pictures', ['disk' => $disk]),
            'scan_status' => MediaScanStatus::Pending,
        ]);

        return $client->uploadResumable($contents, $mimeType, $filename);
    }
}
