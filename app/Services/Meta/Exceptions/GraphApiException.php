<?php

namespace App\Services\Meta\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class GraphApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $error  Meta's error object, verbatim
     */
    public function __construct(
        public readonly int $status,
        public readonly ?int $errorCode,
        public readonly ?int $errorSubcode,
        public readonly array $error,
    ) {
        parent::__construct(
            sprintf('Graph API error %s (HTTP %d): %s', $errorCode ?? 'unknown', $status, $error['message'] ?? 'no message'),
        );
    }

    public static function fromResponse(Response $response): self
    {
        /** @var array<string, mixed> $error */
        $error = $response->json('error') ?? [];

        return new self(
            status: $response->status(),
            errorCode: isset($error['code']) ? (int) $error['code'] : null,
            errorSubcode: isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            error: $error,
        );
    }
}
