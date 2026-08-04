<?php

namespace Tests\Fakes;

use App\Services\Meta\Exceptions\GraphApiException;
use App\Services\Meta\GraphClient;
use Illuminate\Support\Str;

/**
 * In-memory Graph API double (docs/06 §Fakes). Returns fixtures keyed by
 * method+path; can be told to fail with a specific Meta error code.
 */
class FakeGraphClient implements GraphClient
{
    /** @var array<string, array<string, mixed>> */
    private array $responses = [];

    /** @var array<int, array{method: string, path: string, payload: array<string, mixed>}> */
    public array $calls = [];

    private ?int $failWithCode = null;

    public function withToken(string $token): static
    {
        return $this;
    }

    /**
     * Queue a canned response for "METHOD path".
     *
     * @param  array<string, mixed>  $response
     */
    public function fake(string $methodAndPath, array $response): self
    {
        $this->responses[$methodAndPath] = $response;

        return $this;
    }

    /**
     * Make the next call fail with the given Meta error code
     * (131056, 131047, 131026, 131042, 133010, 190, 368, 80007, 130429…).
     */
    public function failWith(int $code): self
    {
        $this->failWithCode = $code;

        return $this;
    }

    public function sendMessage(string $phoneNumberId, array $payload): array
    {
        return $this->record('POST', "{$phoneNumberId}/messages", $payload, fallback: [
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => $payload['to'] ?? '', 'wa_id' => $payload['to'] ?? '']],
            'messages' => [['id' => 'wamid.'.Str::random(28), 'message_status' => 'accepted']],
        ]);
    }

    public function get(string $path, array $params = []): array
    {
        return $this->record('GET', $path, $params, fallback: []);
    }

    public function post(string $path, array $payload = []): array
    {
        return $this->record('POST', $path, $payload, fallback: ['success' => true]);
    }

    public function delete(string $path, array $params = []): array
    {
        return $this->record('DELETE', $path, $params, fallback: ['success' => true]);
    }

    public function exchangeCode(string $code): array
    {
        return $this->record('POST', 'oauth/access_token', ['code' => $code], fallback: [
            'access_token' => 'fake-business-token-'.Str::random(16),
            'token_type' => 'bearer',
        ]);
    }

    public function businessProfile(string $phoneNumberId): array
    {
        return $this->record('GET', "{$phoneNumberId}/whatsapp_business_profile", [], fallback: [
            'data' => [['messaging_product' => 'whatsapp']],
        ]);
    }

    public function updateBusinessProfile(string $phoneNumberId, array $fields): array
    {
        // Mirrors HttpGraphClient: messaging_product is always on the wire, so
        // the recorded payload proves it (docs/reference §5).
        return $this->record('POST', "{$phoneNumberId}/whatsapp_business_profile", [
            'messaging_product' => 'whatsapp',
            ...$fields,
        ], fallback: ['success' => true]);
    }

    public function deregister(string $phoneNumberId): array
    {
        return $this->record('POST', "{$phoneNumberId}/deregister", [], fallback: ['success' => true]);
    }

    public function uploadResumable(string $contents, string $mimeType, string $fileName): string
    {
        $handle = $this->record('POST', 'uploads', [
            'file_name' => $fileName,
            'file_type' => $mimeType,
            'file_length' => strlen($contents),
        ], fallback: ['h' => 'fake-upload-handle-'.Str::random(12)]);

        return (string) ($handle['h'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function record(string $method, string $path, array $payload, array $fallback): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];

        if ($this->failWithCode !== null) {
            $code = $this->failWithCode;
            $this->failWithCode = null;

            throw new GraphApiException(400, $code, null, [
                'message' => "Faked error {$code}",
                'code' => $code,
            ]);
        }

        return $this->responses["{$method} {$path}"] ?? $fallback;
    }
}
