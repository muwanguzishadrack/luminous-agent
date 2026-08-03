<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookSource;
use App\Jobs\ProcessWebhookDelivery;
use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The most important endpoint in the system (docs/modules/m1-team-inbox.md §1):
 * verify → persist raw → 200 immediately → process async. Never lose a webhook.
 */
class MetaWebhookController
{
    /**
     * GET verification handshake: echo hub.challenge when the verify token
     * matches (docs/reference/whatsapp-webhooks.md §1).
     */
    public function verify(Request $request, string $app): Response
    {
        abort_unless($app === config('meta.app_id'), 404);

        $verifyToken = (string) config('meta.webhook_verify_token');

        abort_unless(
            $request->query('hub_mode') === 'subscribe'
                && $verifyToken !== ''
                && hash_equals($verifyToken, (string) $request->query('hub_verify_token')),
            403,
        );

        return response((string) $request->query('hub_challenge'), 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * POST delivery: signature over the RAW body, persist, ack < 50ms.
     */
    public function ingest(Request $request, string $app): Response
    {
        abort_unless($app === config('meta.app_id'), 404);

        $raw = $request->getContent();

        $expected = hash_hmac('sha256', $raw, (string) config('meta.app_secret'));

        abort_unless(
            hash_equals("sha256={$expected}", (string) $request->header('X-Hub-Signature-256', '')),
            401,
        );

        $sha256 = hash('sha256', $raw);

        // ON CONFLICT DO NOTHING keeps duplicate deliveries exception-free —
        // Meta retries aggressively, and a thrown-and-caught unique violation
        // would abort any surrounding Postgres transaction.
        $inserted = WebhookDelivery::query()->getQuery()->insertOrIgnore([
            'source' => WebhookSource::Meta->value,
            'body_sha256' => $sha256,
            'headers' => json_encode([
                'x-hub-signature-256' => $request->header('X-Hub-Signature-256'),
            ]),
            'payload' => $raw !== '' ? $raw : '{}',
            'received_at' => now(),
            'attempts' => 0,
            'status' => WebhookDeliveryStatus::Pending->value,
        ]);

        if ($inserted === 0) {
            // Duplicate delivery — a no-op ack.
            return response()->noContent(200);
        }

        $deliveryId = (int) WebhookDelivery::query()
            ->where('source', WebhookSource::Meta)
            ->where('body_sha256', $sha256)
            ->value('id');

        ProcessWebhookDelivery::dispatch($deliveryId);

        return response()->noContent(200);
    }
}
