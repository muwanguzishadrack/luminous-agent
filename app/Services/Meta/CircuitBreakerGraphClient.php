<?php

namespace App\Services\Meta;

use App\Models\MetaCredential;
use App\Services\Meta\Exceptions\CredentialRevoked;
use App\Services\Meta\Exceptions\GraphApiException;

/**
 * Decorates a GraphClient with the token-vault circuit breaker
 * (docs/modules/m0-onboarding.md §2): three consecutive 190/HTTP-401 auth
 * failures revoke the credential and surface a typed CredentialRevoked that
 * the UI renders as a reconnect prompt — never a 500. Any success resets
 * the consecutive-failure count.
 */
class CircuitBreakerGraphClient implements GraphClient
{
    public const FAILURE_THRESHOLD = 3;

    public function __construct(
        private GraphClient $inner,
        private readonly MetaCredential $credential,
    ) {}

    public function withToken(string $token): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withToken($token);

        return $clone;
    }

    public function sendMessage(string $phoneNumberId, array $payload): array
    {
        return $this->guard(fn (): array => $this->inner->sendMessage($phoneNumberId, $payload));
    }

    public function get(string $path, array $params = []): array
    {
        return $this->guard(fn (): array => $this->inner->get($path, $params));
    }

    public function post(string $path, array $payload = []): array
    {
        return $this->guard(fn (): array => $this->inner->post($path, $payload));
    }

    public function delete(string $path, array $params = []): array
    {
        return $this->guard(fn (): array => $this->inner->delete($path, $params));
    }

    public function exchangeCode(string $code): array
    {
        // Authenticated with the app credentials, not this vaulted token —
        // an auth failure here says nothing about the credential's health.
        return $this->inner->exchangeCode($code);
    }

    public function businessProfile(string $phoneNumberId): array
    {
        return $this->guard(fn (): array => $this->inner->businessProfile($phoneNumberId));
    }

    public function updateBusinessProfile(string $phoneNumberId, array $fields): array
    {
        return $this->guard(fn (): array => $this->inner->updateBusinessProfile($phoneNumberId, $fields));
    }

    public function uploadResumable(string $contents, string $mimeType, string $fileName): string
    {
        return $this->guard(fn (): string => $this->inner->uploadResumable($contents, $mimeType, $fileName));
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $call
     * @return TReturn
     */
    private function guard(callable $call): mixed
    {
        if ($this->credential->revoked_at !== null) {
            throw new CredentialRevoked($this->credential);
        }

        try {
            $result = $call();
        } catch (GraphApiException $e) {
            $this->recordAuthFailure($e);

            throw $e;
        }

        $this->credential->forceFill([
            'failure_count' => 0,
            'last_used_at' => now(),
        ])->save();

        return $result;
    }

    private function recordAuthFailure(GraphApiException $e): void
    {
        // Only expired/invalid-token responses count toward the breaker
        // (docs/reference/whatsapp-cloud-api.md §8, code 190).
        if ($e->errorCode !== 190 && $e->status !== 401) {
            return;
        }

        $this->credential->failure_count++;

        if ($this->credential->failure_count >= self::FAILURE_THRESHOLD) {
            $this->credential->forceFill(['revoked_at' => now()])->save();

            throw new CredentialRevoked($this->credential, $e);
        }

        $this->credential->save();
    }
}
