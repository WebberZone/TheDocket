<?php

namespace Tests\Feature\Items;

use App\Exceptions\FetchFailedException;
use App\Services\Fetching\IpGuard;
use App\Services\Fetching\SafeHttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * SafeHttpClient through the real Guzzle transport, not Http::fake().
 *
 * A made-up hostname is resolved by the fake DNS to 127.0.0.1, where a PHP
 * built-in server is listening. The only way a request reaches it is the
 * client's CURLOPT_RESOLVE pin, so these tests prove the pin is applied by
 * the transport actually selected (a handler that ignored or rejected it
 * would fail to resolve the name or throw). The loopback block is lifted
 * for this one fixture; everything else about the client is real.
 */
class RealTransportTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'docket-fixture.test';

    private Process $server;

    private int $port;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('curl')) {
            $this->markTestSkipped('The curl extension is required.');
        }

        $this->port = $this->freePort();
        $this->server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", base_path('tests/Fixtures/server/router.php')]);
        $this->server->start();
        $this->waitForServer();

        Http::allowStrayRequests();
        $this->dns->set(self::HOST, '127.0.0.1');
        $this->app->instance(IpGuard::class, new class() extends IpGuard
        {
            public function allows(string $ip): bool
            {
                return $ip === '127.0.0.1';
            }
        });
    }

    protected function tearDown(): void
    {
        $this->server->stop(0);

        parent::tearDown();
    }

    private function url(string $path): string
    {
        return 'http://'.self::HOST.':'.$this->port.$path;
    }

    public function test_a_hostname_is_fetched_through_the_pinned_address(): void
    {
        $response = app(SafeHttpClient::class)->get($this->url('/article'), 10_000);

        $this->assertSame(200, $response->status);
        $this->assertSame('text/html', $response->contentType);
        $this->assertSame('utf-8', $response->charset);
        // The Host header is still the hostname: only the connection is pinned.
        $this->assertStringContainsString('Served to '.self::HOST.':'.$this->port, $response->body);
    }

    public function test_redirects_are_followed_over_the_real_transport(): void
    {
        $response = app(SafeHttpClient::class)->get($this->url('/redirect'), 10_000);

        $this->assertSame($this->url('/article'), $response->url);
    }

    public function test_the_size_cap_aborts_a_declared_oversized_body(): void
    {
        $this->expectException(FetchFailedException::class);
        $this->expectExceptionMessage('larger than');

        app(SafeHttpClient::class)->get($this->url('/declared-large'), 10_000);
    }

    public function test_the_size_cap_aborts_while_an_undeclared_body_downloads(): void
    {
        $this->expectException(FetchFailedException::class);
        $this->expectExceptionMessage('larger than');

        app(SafeHttpClient::class)->get($this->url('/streamed-large'), 10_000);
    }

    public function test_a_hostname_on_a_blocked_address_never_connects(): void
    {
        $this->app->instance(IpGuard::class, new IpGuard());

        $this->expectException(FetchFailedException::class);
        $this->expectExceptionMessage('private or local network address');

        app(SafeHttpClient::class)->get($this->url('/article'), 10_000);
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($socket, $error);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitForServer(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        $this->fail('The fixture server did not start: '.$this->server->getErrorOutput());
    }
}
