<?php

use Tests\Fakes\FakeGraphClient;
use Tests\RefreshesTestDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshesTestDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Canned Graph fixtures for a full onboarding of one WABA + one number.
 *
 * @param  array<string, mixed>  $overrides
 */
function primeGraphFixtures(FakeGraphClient $fake, string $wabaId, string $phoneNumberId, array $overrides = []): void
{
    $fake->fake("GET {$wabaId}/subscribed_apps", $overrides['subscribed_apps'] ?? [
        'data' => [['whatsapp_business_api_data' => [
            'id' => config('meta.app_id'),
            'name' => 'Luminous',
            'link' => 'https://luminous.test',
        ]]],
    ]);

    $fake->fake("GET {$wabaId}", [
        'id' => $wabaId,
        'name' => 'Acme Stores',
        'currency' => 'UGX',
        'timezone_id' => 'Africa/Kampala',
        'account_review_status' => 'APPROVED',
        'business_verification_status' => 'verified',
        'owner_business_info' => ['id' => '515151515151515', 'name' => 'Acme Holdings'],
        'is_payment_enabled' => true,
    ]);

    $fake->fake("GET {$wabaId}/phone_numbers", ['data' => [[
        'id' => $phoneNumberId,
        'verified_name' => 'Acme Stores',
        'display_phone_number' => '+256 700 000 001',
        'quality_rating' => 'GREEN',
        'throughput' => ['level' => 'STANDARD'],
        'platform_type' => 'CLOUD_API',
        'is_on_biz_app' => $overrides['is_on_biz_app'] ?? false,
        'code_verification_status' => 'VERIFIED',
    ]]]);

    $fake->fake("GET {$wabaId}/message_templates", ['data' => [
        [
            'id' => '771111111111111',
            'name' => 'order_update',
            'language' => 'en',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Your order {{1}} has shipped.']],
            'quality_score' => ['score' => 'GREEN', 'date' => 1754179200],
        ],
        [
            'id' => '772222222222222',
            'name' => 'welcome_offer',
            'language' => 'en',
            'category' => 'MARKETING',
            'status' => 'PENDING',
            'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, welcome!']],
        ],
    ], 'paging' => ['cursors' => ['before' => 'BEFORE', 'after' => 'AFTER']]]);
}
