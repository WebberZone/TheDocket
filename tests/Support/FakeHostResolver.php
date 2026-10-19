<?php

namespace Tests\Support;

use App\Services\Fetching\HostResolver;

/**
 * DNS for tests: hosts resolve from a fixed map, and anything not in it
 * gets a public documentation-free address so the SSRF guard lets it
 * through to Http::fake().
 */
class FakeHostResolver extends HostResolver
{
    public const PUBLIC_ADDRESS = '93.184.216.34';

    /**
     * @var array<string, list<string>>
     */
    protected array $map = [];

    /**
     * @param  string|list<string>  $addresses
     */
    public function set(string $host, string|array $addresses): static
    {
        $this->map[strtolower($host)] = (array) $addresses;

        return $this;
    }

    public function resolve(string $host): array
    {
        $host = strtolower(trim($host, '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->map[$host] ?? [self::PUBLIC_ADDRESS];
    }
}
