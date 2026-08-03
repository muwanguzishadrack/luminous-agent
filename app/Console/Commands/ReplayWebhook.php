<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Replay a webhook fixture through the REAL route with a correctly computed
 * signature, so signature verification and ingest are both exercised
 * (docs/reference/whatsapp-webhooks.md §9).
 *
 *   php artisan webhook:replay tests/Fixtures/meta/messages/text_inbound.json
 */
class ReplayWebhook extends Command
{
    protected $signature = 'webhook:replay {fixture : Path to a JSON fixture, absolute or relative to the project root}';

    protected $description = 'POST a fixture through the Meta webhook route with a valid signature';

    public function handle(): int
    {
        $fixture = (string) $this->argument('fixture');
        $path = str_starts_with($fixture, '/') ? $fixture : base_path($fixture);

        if (! is_file($path)) {
            $this->error("Fixture not found: {$path}");

            return self::FAILURE;
        }

        $raw = (string) file_get_contents($path);
        $signature = 'sha256='.hash_hmac('sha256', $raw, (string) config('meta.app_secret'));

        $request = Request::create(
            uri: '/webhooks/meta',
            method: 'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            content: $raw,
        );

        $response = app()->handle($request);

        $this->line("HTTP {$response->getStatusCode()}");

        return $response->getStatusCode() === 200 ? self::SUCCESS : self::FAILURE;
    }
}
