<?php

namespace Tests;

use App\Services\Fetching\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeHostResolver;

abstract class TestCase extends BaseTestCase
{
    protected FakeHostResolver $dns;

    /**
     * Per-test installer state (marker, setup code, lock), so no test sees
     * another's installation and none touches storage/app/install.
     */
    protected string $installState;

    protected function setUp(): void
    {
        parent::setUp();

        // No test talks to the network: every hostname resolves through a
        // fixed map (public by default), and any HTTP request without a
        // matching Http::fake() fails loudly.
        $this->dns = new FakeHostResolver();
        $this->app->instance(HostResolver::class, $this->dns);

        Http::preventStrayRequests();

        $this->installState = sys_get_temp_dir().'/docket-install-state-'.Str::random(10);
        config(['docket.install.state_path' => $this->installState]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->installState);

        parent::tearDown();
    }
}
