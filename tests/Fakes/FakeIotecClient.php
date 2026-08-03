<?php

namespace Tests\Fakes;

use App\Services\Iotec\IotecClient;
use Illuminate\Support\Str;

/**
 * In-memory ioTec double implementing the four test-MSISDN behaviours from
 * ioTec's own docs (docs/06 §Fakes):
 *   011177777x → Success · 011177799x → Failed
 *   011177778x → Pending · 011177779x → SentToVendor
 */
class FakeIotecClient implements IotecClient
{
    /** @var array<string, array<string, mixed>> */
    public array $transactions = [];

    public function collect(array $payload): array
    {
        $payer = (string) ($payload['payer'] ?? '');
        $status = $this->statusForMsisdn($payer);

        $transaction = [
            'id' => (string) Str::uuid(),
            'externalId' => $payload['externalId'] ?? null,
            'status' => $status,
            'statusCode' => null,
            'statusMessage' => "Faked {$status}",
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? 'ITX',
            'payer' => $payer,
            'category' => $payload['category'] ?? 'MobileMoney',
            'walletId' => $payload['walletId'] ?? null,
            'vendorTransactionId' => null,
            'transactionCharge' => 0,
            'vendorCharge' => 0,
            'totalTransactionCharge' => 0,
        ];

        $this->transactions[$transaction['id']] = $transaction;

        return $transaction;
    }

    public function collectionStatus(string $id): array
    {
        return $this->transactions[$id] ?? ['id' => $id, 'status' => 'Pending'];
    }

    public function collectionByExternalId(string $externalId): array
    {
        foreach ($this->transactions as $transaction) {
            if ($transaction['externalId'] === $externalId) {
                return $transaction;
            }
        }

        return [];
    }

    public function disburse(array $payload): array
    {
        $transaction = [
            'id' => (string) Str::uuid(),
            'externalId' => $payload['externalId'] ?? null,
            'status' => 'AwaitingApproval',
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? 'ITX',
            'payee' => $payload['payee'] ?? null,
        ];

        $this->transactions[$transaction['id']] = $transaction;

        return $transaction;
    }

    public function disbursementStatus(string $id): array
    {
        return $this->transactions[$id] ?? ['id' => $id, 'status' => 'Pending'];
    }

    public function wallet(string $walletId): array
    {
        return [
            'id' => $walletId,
            'name' => 'Fake Wallet',
            'currency' => 'ITX',
            'actualBalance' => 1_000_000,
            'availableBalance' => 1_000_000,
        ];
    }

    /**
     * Force a transaction to a given status (poller / callback tests).
     */
    public function setStatus(string $id, string $status): void
    {
        if (isset($this->transactions[$id])) {
            $this->transactions[$id]['status'] = $status;
        }
    }

    private function statusForMsisdn(string $msisdn): string
    {
        return match (true) {
            str_starts_with($msisdn, '011177777') => 'Success',
            str_starts_with($msisdn, '011177799') => 'Failed',
            str_starts_with($msisdn, '011177778') => 'Pending',
            str_starts_with($msisdn, '011177779') => 'SentToVendor',
            default => 'Pending',
        };
    }
}
