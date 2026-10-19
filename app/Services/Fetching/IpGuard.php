<?php

namespace App\Services\Fetching;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Decides whether an IP address is one Docket may connect to. Only
 * globally routable unicast addresses pass: never loopback, private,
 * link-local (including cloud metadata at 169.254.169.254), carrier-grade
 * NAT, multicast, documentation or reserved ranges, and never an IPv6
 * address that embeds one of those (IPv4-mapped, NAT64, 6to4, Teredo).
 */
class IpGuard
{
    /**
     * Belt and braces alongside FILTER_FLAG_GLOBAL_RANGE, so the policy is
     * readable here rather than buried in PHP's filter implementation.
     */
    public const BLOCKED_RANGES = [
        // IPv4
        '0.0.0.0/8',          // "this" network
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // carrier-grade NAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, cloud metadata
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation
        '203.0.113.0/24',     // documentation
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, broadcast
        // IPv6
        '::/128',             // unspecified
        '::1/128',            // loopback
        '::ffff:0:0/96',      // IPv4-mapped (checked separately too)
        '64:ff9b::/96',       // NAT64
        '64:ff9b:1::/48',     // local-use NAT64
        '100::/64',           // discard-only
        '2001::/32',          // Teredo
        '2001:db8::/32',      // documentation
        '2002::/16',          // 6to4
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'fec0::/10',          // site-local (deprecated)
        'ff00::/8',           // multicast
    ];

    public static function isPublic(string $ip): bool
    {
        $ip = trim($ip, '[] ');

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $embedded = self::embeddedIpv4($ip);

        if ($embedded !== null && ! self::isPublic($embedded)) {
            return false;
        }

        if (IpUtils::checkIp($ip, self::BLOCKED_RANGES)) {
            return false;
        }

        // PHPStan doesn't model FILTER_FLAG_GLOBAL_RANGE and assumes any
        // valid IP passes; it doesn't (e.g. 192.0.0.0/24 sub-ranges).
        // @phpstan-ignore notIdentical.alwaysTrue
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /**
     * The IPv4 address inside an IPv4-mapped (::ffff:a.b.c.d) or
     * IPv4-compatible (::a.b.c.d) IPv6 address, if it is one.
     */
    protected static function embeddedIpv4(string $ip): ?string
    {
        if (! str_contains($ip, ':')) {
            return null;
        }

        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $prefix = substr($packed, 0, 12);

        if ($prefix === str_repeat("\0", 10)."\xff\xff" || $prefix === str_repeat("\0", 12)) {
            $v4 = inet_ntop(substr($packed, 12));

            return $v4 === false ? null : $v4;
        }

        return null;
    }
}
