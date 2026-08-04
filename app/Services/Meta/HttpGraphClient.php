<?php

namespace App\Services\Meta;

use App\Services\Meta\Exceptions\GraphApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HttpGraphClient implements GraphClient
{
    private ?string $token = null;

    public function withToken(string $token): static
    {
        $clone = clone $this;
        $clone->token = $token;

        return $clone;
    }

    public function sendMessage(string $phoneNumberId, array $payload): array
    {
        return $this->post("{$phoneNumberId}/messages", $payload);
    }

    public function get(string $path, array $params = []): array
    {
        return $this->decode($this->request()->get($this->url($path), $params));
    }

    public function post(string $path, array $payload = []): array
    {
        return $this->decode($this->request()->post($this->url($path), $payload));
    }

    public function delete(string $path, array $params = []): array
    {
        return $this->decode($this->request()->delete($this->url($path), $params));
    }

    public function exchangeCode(string $code): array
    {
        return $this->decode(Http::asForm()->post($this->url('oauth/access_token'), [
            'client_id' => config('meta.app_id'),
            'client_secret' => config('meta.app_secret'),
            'code' => $code,
        ]));
    }

    public function businessProfile(string $phoneNumberId): array
    {
        return $this->get("{$phoneNumberId}/whatsapp_business_profile", [
            'fields' => 'about,address,description,email,profile_picture_url,websites,vertical',
        ]);
    }

    public function updateBusinessProfile(string $phoneNumberId, array $fields): array
    {
        return $this->post("{$phoneNumberId}/whatsapp_business_profile", [
            'messaging_product' => 'whatsapp',
            ...$fields,
        ]);
    }

    public function deregister(string $phoneNumberId): array
    {
        return $this->post("{$phoneNumberId}/deregister");
    }

    public function uploadResumable(string $contents, string $mimeType, string $fileName): string
    {
        $session = $this->decode($this->request()->post($this->url(config('meta.app_id').'/uploads'), [
            'file_name' => $fileName,
            'file_length' => strlen($contents),
            'file_type' => $mimeType,
        ]));

        $sessionId = (string) ($session['id'] ?? '');

        // The upload leg is the one Graph call that authenticates with the
        // `OAuth` scheme rather than `Bearer`, and carries the bytes raw.
        $upload = $this->decode(
            Http::withHeaders(array_filter([
                'Authorization' => $this->token === null ? null : "OAuth {$this->token}",
                'file_offset' => '0',
            ]))
                ->withBody($contents, $mimeType)
                ->timeout(60)
                ->post($this->url($sessionId)),
        );

        return (string) ($upload['h'] ?? '');
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()->timeout(30);

        return $this->token !== null ? $request->withToken($this->token) : $request;
    }

    private function url(string $path): string
    {
        return sprintf(
            '%s/%s/%s',
            config('meta.graph_base_url'),
            config('meta.graph_version'),
            ltrim($path, '/'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->failed()) {
            // The full error payload is preserved verbatim — never discard it
            // (docs/reference/whatsapp-cloud-api.md §8).
            throw GraphApiException::fromResponse($response);
        }

        return (array) $response->json();
    }
}
