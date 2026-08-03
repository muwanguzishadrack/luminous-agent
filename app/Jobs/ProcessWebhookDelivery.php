<?php

namespace App\Jobs;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\FieldHandlerRegistry;
use App\Support\Facades\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fan out a persisted delivery per entry/change into per-field handlers, with
 * each change isolated so one bad object never loses its siblings
 * (docs/modules/m1-team-inbox.md §1).
 *
 * Tenant resolution goes through the SECURITY DEFINER resolve_webhook_tenant
 * function (owned by the BYPASSRLS migrator role) — the webhook arrives with
 * no tenant context and the lookup is inherently cross-tenant (docs/05 §1,
 * "dangerous paths"). Once resolved, the change is handled under normal
 * tenant context so RLS applies to every write.
 */
class ProcessWebhookDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 15, 60, 300];

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(FieldHandlerRegistry $registry): void
    {
        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->status === WebhookDeliveryStatus::Processed) {
            return;
        }

        $delivery->increment('attempts');

        $failures = [];
        $handled = 0;
        $parked = 0;

        foreach ($delivery->payload['entry'] ?? [] as $entryIndex => $entry) {
            foreach ($entry['changes'] ?? [] as $changeIndex => $change) {
                try {
                    $result = $this->handleChange($registry, $entry, $change);
                    $result === 'parked' ? $parked++ : $handled++;
                } catch (Throwable $e) {
                    $failures["{$entryIndex}.{$changeIndex}"] = [
                        'field' => $change['field'] ?? null,
                        'message' => $e->getMessage(),
                        'exception' => $e::class,
                    ];
                } finally {
                    Tenancy::forget();
                }
            }
        }

        $delivery->forceFill([
            'processed_at' => now(),
            'status' => match (true) {
                $failures !== [] && $handled === 0 => WebhookDeliveryStatus::Failed,
                $failures !== [] => WebhookDeliveryStatus::Partial,
                $handled === 0 && $parked > 0 => WebhookDeliveryStatus::Ignored,
                default => WebhookDeliveryStatus::Processed,
            },
            'error' => $failures ?: null,
        ])->save();

        if ($failures !== [] && $this->job !== null && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $change
     * @return 'handled'|'parked'
     */
    private function handleChange(FieldHandlerRegistry $registry, array $entry, array $change): string
    {
        $field = (string) ($change['field'] ?? '');
        $value = (array) ($change['value'] ?? []);

        $handler = $registry->for($field);

        if ($handler === null) {
            // No handler yet (later-phase field, or out-of-scope like calls/groups).
            return 'parked';
        }

        $tenant = $this->resolveTenant($entry, $value);

        if ($tenant === null) {
            // Never guess a tenant (docs/m1 §1 rule 5). Park and let the
            // delivery record carry the evidence.
            throw new \RuntimeException(
                "Unresolvable tenant for field [{$field}] (waba ".($entry['id'] ?? '?').')',
            );
        }

        Tenancy::initialize($tenant);

        $handler->handle($tenant, $value, $entry);

        return 'handled';
    }

    /**
     * Resolve the owning tenant from metadata.phone_number_id, falling back
     * to the entry-level WABA id.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $value
     */
    private function resolveTenant(array $entry, array $value): ?Tenant
    {
        $tenantId = DB::selectOne(
            'select resolve_webhook_tenant(?, ?) as tenant_id',
            [
                $value['metadata']['phone_number_id'] ?? null,
                $entry['id'] ?? null,
            ],
        )->tenant_id ?? null;

        return $tenantId === null ? null : Tenant::query()->whereKey((string) $tenantId)->first();
    }
}
