<?php

namespace App\Services\Fetching;

/**
 * Resolves a hostname to every address it points at, so each one can be
 * vetted before connecting. Bound in the container so tests can swap in a
 * fixed map instead of touching real DNS.
 */
class HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ip'])) {
                $addresses[] = (string) $record['ip'];
            }

            if (isset($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }

        // gethostbynamel() also reads /etc/hosts and understands numeric
        // forms such as "2130706433" or "0x7f.1", which dns_get_record()
        // does not, so those still get vetted (and refused).
        $fallback = @gethostbynamel($host);

        if (is_array($fallback)) {
            $addresses = array_merge($addresses, $fallback);
        }

        return array_values(array_unique($addresses));
    }
}
