<?php

namespace App\Services\Iotec;

use App\Services\Iotec\Exceptions\IotecApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class HttpIotecClient implements IotecClient
{
    public function collect(array $payload): array
    {
        return $this->decode($this->request()->post($this->url('api/collections/collect'), $payload));
    }

    public function collectionStatus(string $id): array
    {
        return $this->decode($this->request()->get($this->url("api/collections/status/{$id}")));
    }

    public function collectionByExternalId(string $externalId): array
    {
        return $this->decode($this->request()->get($this->url("api/collections/external-id/{$externalId}")));
    }

    public function disburse(array $payload): array
    {
        return $this->decode($this->request()->post($this->url('api/disbursements/disburse'), $payload));
    }

    public function disbursementStatus(string $id): array
    {
        return $this->decode($this->request()->get($this->url("api/disbursements/status/{$id}")));
    }

    public function wallet(string $walletId): array
    {
        return $this->decode($this->request()->get($this->url("api/wallets/{$walletId}")));
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->timeout(30)->withToken($this->accessToken());
    }

    /**
     * ioTec access tokens live 300s — cache 240s with single-flight so
     * concurrent jobs don't stampede the token endpoint
     * (docs/reference/pricing-and-limits.md §5).
     */
    private function accessToken(): string
    {
        return Cache::lock('iotec:token:lock', 10)->block(11, function (): string {
            return Cache::remember('iotec:token', config('limits.iotec.token_ttl'), function (): string {
                $response = Http::asForm()->post(config('iotec.token_url'), [
                    'grant_type' => 'client_credentials',
                    'client_id' => config('iotec.client_id'),
                    'client_secret' => config('iotec.client_secret'),
                ]);

                if ($response->failed()) {
                    throw IotecApiException::fromResponse($response);
                }

                return (string) $response->json('access_token');
            });
        });
    }

    private function url(string $path): string
    {
        return rtrim((string) config('iotec.base_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->failed()) {
            throw IotecApiException::fromResponse($response);
        }

        return (array) $response->json();
    }
}
