<?php

use App\Http\Controllers\Webhooks\MetaWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public HTTP surfaces (docs/01-architecture.md)
|--------------------------------------------------------------------------
| The only routes reachable from the internet without a session. Registered
| WITHOUT the `web` middleware group: no session, no CSRF, and nothing that
| re-encodes the raw body (signature verification depends on it).
*/

Route::get('/webhooks/meta/{app}', [MetaWebhookController::class, 'verify']);
Route::post('/webhooks/meta/{app}', [MetaWebhookController::class, 'ingest']);
