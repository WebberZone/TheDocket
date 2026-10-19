<?php

namespace Tests;

use App\Services\Fetching\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;

abstract class TestCase extends BaseTestCase
{
    protected FakeHostResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        // No test talks to the network: every hostname resolves through a
        // fixed map (public by default), and any HTTP request without a
        // matching Http::fake() fails loudly.
        $this->dns = new FakeHostResolver();
        $this->app->instance(HostResolver::class, $this->dns);

        Http::preventStrayRequests();
    }
}
