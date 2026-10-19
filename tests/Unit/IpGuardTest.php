<?php

namespace Tests\Unit;

use App\Services\Fetching\IpGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function addresses(): array
    {
        return [
            'public IPv4' => ['93.184.216.34', true],
            'public IPv4 (DNS resolver)' => ['1.1.1.1', true],
            'public IPv6' => ['2606:4700:4700::1111', true],
            'loopback' => ['127.0.0.1', false],
            'loopback range' => ['127.255.0.9', false],
            'private 10/8' => ['10.0.0.1', false],
            'private 172.16/12' => ['172.31.255.255', false],
            'private 192.168/16' => ['192.168.0.1', false],
            'link-local / metadata' => ['169.254.169.254', false],
            'carrier-grade NAT' => ['100.64.0.1', false],
            'this network' => ['0.0.0.0', false],
            'documentation' => ['192.0.2.10', false],
            'benchmarking' => ['198.18.0.1', false],
            'multicast' => ['224.0.0.251', false],
            'broadcast' => ['255.255.255.255', false],
            'IPv6 loopback' => ['::1', false],
            'IPv6 unspecified' => ['::', false],
            'IPv6 bracketed loopback' => ['[::1]', false],
            'IPv4-mapped loopback' => ['::ffff:127.0.0.1', false],
            'IPv4-mapped private' => ['::ffff:10.0.0.1', false],
            'IPv4-compatible loopback' => ['::127.0.0.1', false],
            'NAT64 of loopback' => ['64:ff9b::7f00:1', false],
            '6to4' => ['2002:7f00:1::1', false],
            'unique local' => ['fd12:3456::1', false],
            'IPv6 link-local' => ['fe80::1', false],
            'IPv6 multicast' => ['ff02::1', false],
            'not an address' => ['example.com', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_only_public_unicast_addresses_pass(string $address, bool $public): void
    {
        $this->assertSame($public, IpGuard::isPublic($address));
    }
}
