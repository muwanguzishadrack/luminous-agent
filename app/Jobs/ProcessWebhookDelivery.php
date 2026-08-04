<?php

namespace App\Jobs;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Team;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\FieldHandlerRegistry;
use App\Support\Facades\Teams;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fan out a persisted delivery per entry/change into per-field handlers, with
 * each change isolated so one bad object never loses its siblings
 * (docs/modules/m1-team-inbox.md §1).
 *
 * Team resolution goes through the SECURITY DEFINER resolve_webhook_team
 * function (owned by the BYPASSRLS migrator role) — the webhook arrives with
 * no team context and the lookup is inherently cross-team (docs/05 §1,
 * "dangerous paths"). Once resolved, the change is handled under normal
 * team context so RLS applies to every write.
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
                    Teams::forget();
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

        $team = $this->resolveTeam($entry, $value);

        if ($team === null) {
            // Never guess a team (docs/m1 §1 rule 5). Park and let the
            // delivery record carry the evidence.
            throw new \RuntimeException(
                "Unresolvable team for field [{$field}] (waba ".($entry['id'] ?? '?').')',
            );
        }

        Teams::initialize($team);

        $handler->handle($team, $value, $entry);

        return 'handled';
    }

    /**
     * Resolve the owning team from metadata.phone_number_id, falling back
     * to the entry-level WABA id.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $value
     */
    private function resolveTeam(array $entry, array $value): ?Team
    {
        // Explicit ::text casts: without them Postgres sees `unknown` argument
        // types and cannot resolve the overload.
        $teamId = DB::selectOne(
            'select resolve_webhook_team(?::text, ?::text) as team_id',
            [
                $value['metadata']['phone_number_id'] ?? null,
                $entry['id'] ?? null,
            ],
        )->team_id ?? null;

        return $teamId === null ? null : Team::query()->whereKey((string) $teamId)->first();
    }
}
