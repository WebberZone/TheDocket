<?php

namespace Tests\Feature\Items;

use App\Enums\FetchStatus;
use App\Exceptions\FetchFailedException;
use App\Exceptions\UnsafeUrlException;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Services\Fetching\SafeHttpClient;
use App\Services\ImageLocaliser;
use App\Services\UrlNormaliser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Saved links are attacker-controlled. None of them may make Docket talk
 * to its own host or private network, however the address is dressed up.
 */
class SsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function client(): SafeHttpClient
    {
        return app(SafeHttpClient::class);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedLiteralUrls(): array
    {
        return [
            'IPv4 loopback' => ['http://127.0.0.1/admin'],
            'loopback, other address' => ['http://127.1.2.3:8080/'],
            'IPv6 loopback' => ['http://[::1]/'],
            'IPv4-mapped IPv6 loopback' => ['http://[::ffff:127.0.0.1]/'],
            'private 10/8' => ['http://10.0.0.1/'],
            'private 172.16/12' => ['https://172.16.5.4/'],
            'private 192.168/16' => ['http://192.168.1.1/router'],
            'cloud metadata (link-local)' => ['http://169.254.169.254/latest/meta-data/'],
            'carrier-grade NAT' => ['http://100.64.0.1/'],
            'unspecified' => ['http://0.0.0.0/'],
            'IPv6 unique local' => ['http://[fd00::1]/'],
            'IPv6 link-local' => ['http://[fe80::1]/'],
        ];
    }

    #[DataProvider('blockedLiteralUrls')]
    public function test_private_and_local_addresses_are_refused(string $url): void
    {
        Http::fake();

        try {
            $this->client()->get($url, 1024);
            $this->fail("{$url} should have been refused.");
        } catch (UnsafeUrlException $e) {
            $this->assertTrue($e->permanent);
        }

        Http::assertNothingSent();
    }

    public function test_hostnames_are_checked_after_dns_resolution(): void
    {
        Http::fake();
        $this->dns->set('intranet.example.com', '10.1.2.3');
        $this->dns->set('localhost', '127.0.0.1');

        foreach (['https://intranet.example.com/', 'http://localhost:3000/'] as $url) {
            try {
                $this->client()->get($url, 1024);
                $this->fail("{$url} should have been refused.");
            } catch (UnsafeUrlException) {
                // Expected.
            }
        }

        Http::assertNothingSent();
    }

    public function test_a_host_with_any_private_address_is_refused(): void
    {
        // A public and a private A record: picking the "good" one is not
        // enough, because the client cannot guarantee which gets used.
        Http::fake();
        $this->dns->set('split.example.com', ['93.184.216.34', '192.168.0.10']);

        $this->expectException(UnsafeUrlException::class);

        $this->client()->get('https://split.example.com/', 1024);
    }

    public function test_redirects_into_the_private_network_are_refused(): void
    {
        $this->dns->set('internal.example.com', '192.168.1.20');
        Http::fake([
            'https://public.example.com/start' => Http::response('', 302, ['Location' => 'http://internal.example.com/secret']),
            '*' => Http::response('should never be fetched'),
        ]);

        try {
            $this->client()->get('https://public.example.com/start', 1024);
            $this->fail('The redirect should have been refused.');
        } catch (UnsafeUrlException) {
            // Expected.
        }

        Http::assertSentCount(1);
    }

    public function test_safe_redirects_are_followed_including_relative_ones(): void
    {
        Http::fake([
            'https://public.example.com/old' => Http::response('', 301, ['Location' => '/new']),
            'https://public.example.com/new' => Http::response('<p>Moved</p>', 200, ['Content-Type' => 'text/html']),
        ]);

        $response = $this->client()->get('https://public.example.com/old', 1024);

        $this->assertSame('https://public.example.com/new', $response->url);
        $this->assertSame('<p>Moved</p>', $response->body);
    }

    public function test_redirect_chains_are_capped(): void
    {
        config(['docket.fetch.max_redirects' => 2]);
        Http::fake(['https://loop.example.com/*' => Http::response('', 302, ['Location' => '/again'])]);

        try {
            $this->client()->get('https://loop.example.com/start', 1024);
            $this->fail('An endless redirect should give up.');
        } catch (FetchFailedException $e) {
            $this->assertStringContainsString('more than 2 redirects', $e->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_responses_over_the_size_cap_are_abandoned(): void
    {
        Http::fake([
            'https://big.example.com/declared' => Http::response(str_repeat('a', 10), 200, ['Content-Length' => '50000']),
            'https://big.example.com/streamed' => Http::response(str_repeat('a', 5000), 200),
        ]);

        foreach (['declared', 'streamed'] as $path) {
            try {
                $this->client()->get("https://big.example.com/{$path}", 1000);
                $this->fail("The {$path} oversize response should have been abandoned.");
            } catch (FetchFailedException $e) {
                $this->assertTrue($e->permanent);
                $this->assertStringContainsString('larger than', $e->getMessage());
            }
        }
    }

    public function test_only_http_and_https_are_fetched(): void
    {
        Http::fake();

        foreach (['ftp://example.com/file', 'file:///etc/passwd', 'gopher://example.com/'] as $url) {
            try {
                $this->client()->get($url, 1024);
                $this->fail("{$url} should have been refused.");
            } catch (UnsafeUrlException) {
                // Expected.
            }
        }

        Http::assertNothingSent();
    }

    public function test_unresolvable_hosts_fail_cleanly(): void
    {
        Http::fake();
        $this->dns->set('nowhere.example.com', []);

        $this->expectException(FetchFailedException::class);
        $this->expectExceptionMessage('Could not find the server for nowhere.example.com.');

        $this->client()->get('https://nowhere.example.com/', 1024);
    }

    public function test_a_saved_link_to_a_private_address_is_recorded_as_failed(): void
    {
        Http::fake();
        $this->dns->set('router.home.arpa', '192.168.1.1');

        $item = Item::factory()->create([
            'url' => 'http://router.home.arpa/admin',
            'normalized_url' => app(UrlNormaliser::class)->normalise('http://router.home.arpa/admin'),
        ]);

        FetchItemContent::dispatchSync($item);
        $item->refresh();

        $this->assertSame(FetchStatus::Failed, $item->fetch_status);
        $this->assertStringContainsString('private or local network address', (string) $item->fetch_error);
        Http::assertNothingSent();
    }

    public function test_article_images_on_private_addresses_are_left_alone(): void
    {
        Storage::fake(Item::DISK);
        Http::fake();
        $this->dns->set('cdn.internal', '10.20.30.40');

        $item = Item::factory()->create();
        $html = '<p>Text</p><img src="http://cdn.internal/pixel.png" alt=""><img src="http://127.0.0.1/x.png" alt="">';

        $result = app(ImageLocaliser::class)->localise($item, $html);

        $this->assertStringContainsString('src="http://cdn.internal/pixel.png"', $result);
        $this->assertStringContainsString('src="http://127.0.0.1/x.png"', $result);
        $this->assertSame([], Storage::disk(Item::DISK)->allFiles());
        Http::assertNothingSent();
    }
}
