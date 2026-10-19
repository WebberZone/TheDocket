<?php

namespace App\Services;

use App\Exceptions\InvalidUrlException;

/**
 * Validates saved URLs and reduces them to the form used for
 * de-duplication, so "HTTPS://Example.com:443/a?utm_source=x&b=2&a=1#top"
 * and "https://example.com/a?a=1&b=2" count as the same link.
 *
 * Normalising: lowercase scheme and host (IDN hosts to punycode), drop the
 * default port, user info and fragment, strip known tracking parameters,
 * sort what remains of the query, and give an empty path a "/".
 */
class UrlNormaliser
{
    public const MAX_LENGTH = 2048;

    /**
     * Query parameters that only ever identify a campaign or click, never
     * the content. Entries ending in "_" match as a prefix.
     */
    public const TRACKING_PARAMETERS = [
        'utm_', 'pk_', 'mtm_',
        'fbclid', 'gclid', 'gclsrc', 'dclid', 'gbraid', 'wbraid', 'msclkid',
        'yclid', 'twclid', 'ttclid', 'li_fat_id', 'igshid', 'mc_cid',
        'mc_eid', '_hsenc', '_hsmi', 'mkt_tok', 'oly_anon_id', 'oly_enc_id',
        'vero_id', 'wickedid', 'rb_clickid', 's_cid', '_ga', '_gl',
    ];

    /**
     * Trim and validate a URL as given, adding "https://" when the scheme is
     * missing (people paste "example.com/article" all the time).
     *
     * @throws InvalidUrlException
     */
    public function clean(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidUrlException('Enter a URL.');
        }

        if (! preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
            if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) && ! preg_match('#^[^:/]+:\d+#', $url)) {
                // mailto:, javascript:, data: and friends.
                throw new InvalidUrlException('Only http and https links can be saved.');
            }

            $url = 'https://'.ltrim($url, '/');
        }

        if (strlen($url) > self::MAX_LENGTH) {
            throw new InvalidUrlException('That URL is too long.');
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            throw new InvalidUrlException('That does not look like a valid URL.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidUrlException('Only http and https links can be saved.');
        }

        if (preg_match('/[\s<>"\x00-\x1f]/', $parts['host'])) {
            throw new InvalidUrlException('That does not look like a valid URL.');
        }

        return $url;
    }

    /**
     * The de-duplication key for a URL.
     *
     * @throws InvalidUrlException
     */
    public function normalise(string $url): string
    {
        $parts = (array) parse_url($this->clean($url));

        $scheme = strtolower((string) $parts['scheme']);
        $host = $this->normaliseHost((string) $parts['host']);

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = (string) ($parts['path'] ?? '');
        $path = $path === '' ? '/' : $this->normalisePercentEncoding($path);

        $query = $this->normaliseQuery((string) ($parts['query'] ?? ''));

        return $scheme.'://'.$host
            .($port !== null ? ':'.$port : '')
            .$path
            .($query !== '' ? '?'.$query : '');
    }

    protected function normaliseHost(string $host): string
    {
        $host = rtrim(strtolower($host), '.');

        if (preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
        }

        return $host;
    }

    protected function normaliseQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key] = explode('=', $pair, 2);

            if ($this->isTrackingParameter(strtolower(rawurldecode($key)))) {
                continue;
            }

            $pairs[] = $this->normalisePercentEncoding($pair);
        }

        // Stable sort by the whole pair keeps repeated keys (a=1&a=2) in a
        // predictable order without merging them.
        sort($pairs, SORT_STRING);

        return implode('&', $pairs);
    }

    protected function isTrackingParameter(string $key): bool
    {
        foreach (self::TRACKING_PARAMETERS as $parameter) {
            if (str_ends_with($parameter, '_') ? str_starts_with($key, $parameter) : $key === $parameter) {
                return true;
            }
        }

        return false;
    }

    /**
     * Uppercase percent-escapes ("%2f" and "%2F" are the same byte).
     */
    protected function normalisePercentEncoding(string $value): string
    {
        return (string) preg_replace_callback(
            '/%[0-9a-f]{2}/i',
            static fn (array $match): string => strtoupper($match[0]),
            $value
        );
    }
}
