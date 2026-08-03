<?php

namespace App\Services\Iotec;

/**
 * Thin typed contract for ioTec Pay (docs/reference/iotec-pay.md).
 * Amounts cross this boundary in MINOR units and are converted at the edge —
 * ioTec speaks doubles in major units (docs/02 conventions).
 */
interface IotecClient
{
    /**
     * POST /api/collections/collect — mobile money / card collection.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function collect(array $payload): array;

    /**
     * GET /api/collections/status/{id} — authoritative status
     * (mandatory re-fetch on every callback, D-010).
     *
     * @return array<string, mixed>
     */
    public function collectionStatus(string $id): array;

    /**
     * GET /api/collections/external-id/{externalId} — recovery path when a
     * create response was lost in flight (D-009).
     *
     * @return array<string, mixed>
     */
    public function collectionByExternalId(string $externalId): array;

    /**
     * POST /api/disbursements/disburse
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function disburse(array $payload): array;

    /**
     * GET /api/disbursements/status/{id}
     *
     * @return array<string, mixed>
     */
    public function disbursementStatus(string $id): array;

    /**
     * GET /api/wallets/{walletId}
     *
     * @return array<string, mixed>
     */
    public function wallet(string $walletId): array;
}
