<?php

namespace App\Services\Iotec\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class IotecApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body  provider response, verbatim
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {
        parent::__construct(sprintf('ioTec API error (HTTP %d)', $status));
    }

    public static function fromResponse(Response $response): self
    {
        return new self($response->status(), (array) $response->json());
    }
}
