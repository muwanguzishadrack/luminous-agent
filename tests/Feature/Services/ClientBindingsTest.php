<?php

use App\Services\Iotec\IotecClient;
use App\Services\Meta\Exceptions\GraphApiException;
use App\Services\Meta\GraphClient;
use Tests\Fakes\FakeGraphClient;
use Tests\Fakes\FakeIotecClient;

test('tests always resolve the fake clients', function () {
    expect(app(GraphClient::class))->toBeInstanceOf(FakeGraphClient::class)
        ->and(app(IotecClient::class))->toBeInstanceOf(FakeIotecClient::class);
});

test('the fake graph client returns an accepted wamid on send', function () {
    $response = app(GraphClient::class)->sendMessage('123456', [
        'messaging_product' => 'whatsapp',
        'to' => '256700000001',
        'type' => 'text',
        'text' => ['body' => 'hello'],
    ]);

    expect($response['messages'][0]['id'])->toStartWith('wamid.')
        ->and($response['messages'][0]['message_status'])->toBe('accepted');
});

test('the fake graph client can fail with a specific meta error code', function () {
    $client = app(GraphClient::class);
    $client->failWith(131056);

    expect(fn () => $client->sendMessage('123456', ['to' => '256700000001']))
        ->toThrow(fn (GraphApiException $e) => expect($e->errorCode)->toBe(131056));
});

test('the fake iotec client implements the documented test msisdn behaviours', function (string $msisdn, string $status) {
    $transaction = app(IotecClient::class)->collect([
        'externalId' => str()->ulid()->toString(),
        'payer' => $msisdn,
        'amount' => 1000,
        'currency' => 'ITX',
    ]);

    expect($transaction['status'])->toBe($status);
})->with([
    ['0111777770', 'Success'],
    ['0111777990', 'Failed'],
    ['0111777780', 'Pending'],
    ['0111777790', 'SentToVendor'],
]);
