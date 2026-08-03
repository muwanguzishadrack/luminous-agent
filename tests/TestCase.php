<?php

namespace Tests;

use App\Services\Iotec\IotecClient;
use App\Services\Meta\GraphClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Fakes\FakeGraphClient;
use Tests\Fakes\FakeIotecClient;

abstract class TestCase extends BaseTestCase
{
    /**
     * No test touches the network: external clients are always fakes
     * (docs/06-testing-strategy.md). Resolve the interface in a test to
     * configure canned responses or failure codes.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(GraphClient::class, FakeGraphClient::class);
        $this->app->singleton(IotecClient::class, FakeIotecClient::class);

    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
