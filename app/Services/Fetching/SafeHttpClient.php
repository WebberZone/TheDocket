<?php

namespace App\Services\Fetching;

use App\Exceptions\FetchFailedException;
use App\Exceptions\ResponseTooLargeException;
use App\Exceptions\UnsafeUrlException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only way Docket fetches anything from the internet. Saved links are
 * attacker-controlled input (a shared link, an imported CSV, a bookmarklet
 * click on a hostile page), so every request is treated as a possible
 * server-side request forgery:
 *
 *  - only http and https;
 *  - the host is resolved here and every address it resolves to must be
 *    public (see IpGuard), then the connection is pinned to the vetted
 *    address with CURLOPT_RESOLVE so a second DNS answer cannot swap in
 *    127.0.0.1 between the check and the connect (DNS rebinding);
 *  - redirects are followed by hand, and each hop is vetted the same way;
 *  - connect and total timeouts, a redirect cap, and a byte cap enforced
 *    on Content-Length and while the body downloads (a progress callback
 *    aborts the transfer as soon as it passes the cap).
 *
 * The transport is always Guzzle's cURL handler. Address pinning is a
 * cURL option, and Guzzle's default handler stack sends some requests
 * through its PHP-stream handler instead, which refuses (Guzzle 8) or
 * silently ignores (Guzzle 7) cURL options. Http::fake() still works: the
 * fake sits in front of whichever handler is set.
 *
 * If the host routes outbound traffic through an HTTP proxy (HTTPS_PROXY
 * and friends), the proxy does its own DNS lookup and the pinning above
 * cannot apply; the address checks still run.
 */
class SafeHttpClient
{
    public function __construct(
        protected HostResolver $resolver,
        protected IpGuard $guard,
    ) {}

    /**
     * GET a URL and return its body, refusing anything unsafe.
     *
     * @param  int  $maxBytes  abandon the download past this many bytes
     * @param  string  $accept  the Accept header to send
     * @param  int|null  $timeout  seconds per request; the fetch.timeout setting when null
     *
     * @throws FetchFailedException
     */
    public function get(string $url, int $maxBytes, string $accept = '*/*', ?int $timeout = null): FetchedResponse
    {
        $maxRedirects = (int) config('docket.fetch.max_redirects', 5);

        for ($hop = 0; ; $hop++) {
            [$host, $port, $address] = $this->vet($url);

            $response = $this->send($url, $host, $port, $address, $accept, $maxBytes, $timeout ?? (int) settings('fetch.timeout'));
            $status = $response->status();
            $location = $response->header('Location');

            if ($status >= 300 && $status < 400 && $location !== '') {
                if ($hop >= $maxRedirects) {
                    throw FetchFailedException::permanent("Gave up after more than {$maxRedirects} redirects.");
                }

                $url = (string) UriResolver::resolve(Utils::uriFor($url), Utils::uriFor($location));

                continue;
            }

            if ($status >= 400) {
                throw new FetchFailedException(
                    "The site responded with HTTP {$status}.",
                    permanent: $status !== 408 && $status !== 429 && $status < 500,
                );
            }

            [$contentType, $charset] = $this->parseContentType($response->header('Content-Type'));

            $length = $response->header('Content-Length');
            if (is_numeric($length) && (int) $length > $maxBytes) {
                throw new ResponseTooLargeException($this->tooLargeMessage($maxBytes), $maxBytes);
            }

            return new FetchedResponse(
                url: $url,
                status: $status,
                contentType: $contentType,
                charset: $charset,
                body: $this->body($response, $maxBytes),
            );
        }
    }

    /**
     * Check the URL's scheme and that every address its host resolves to
     * is public. Returns the host, port and the vetted address to pin.
     *
     * @return array{0: string, 1: int, 2: string}
     *
     * @throws FetchFailedException
     */
    public function vet(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new UnsafeUrlException('Only http and https links can be fetched.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new FetchFailedException("Could not find the server for {$host}.");
        }

        foreach ($addresses as $address) {
            if (! $this->guard->allows($address)) {
                throw new UnsafeUrlException("Refused to fetch {$host}: it points at a private or local network address.");
            }
        }

        return [trim($host, '[]'), $port, $addresses[0]];
    }

    /**
     * @throws FetchFailedException
     */
    protected function send(string $url, string $host, int $port, string $address, string $accept, int $maxBytes, int $timeout): Response
    {
        $pinned = str_contains($address, ':') ? "[{$address}]" : $address;

        // An IP-literal URL has nothing to resolve, so nothing to pin.
        $curl = filter_var($host, FILTER_VALIDATE_IP) === false
            ? [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinned}"]]
            : [];

        $tooLarge = false;

        try {
            return Http::withHeaders([
                'User-Agent' => (string) config('docket.fetch.user_agent'),
                'Accept' => $accept,
                'Accept-Language' => 'en-GB,en;q=0.9,*;q=0.5',
            ])
                ->setHandler(new CurlHandler())
                ->timeout(max(1, $timeout))
                ->connectTimeout((int) config('docket.fetch.connect_timeout', 10))
                ->withoutRedirecting()
                ->withOptions([
                    'protocols' => ['http', 'https'],
                    'curl' => $curl,
                    // Returning true aborts the transfer. The expected size
                    // is known as soon as the headers arrive, so an
                    // oversized Content-Length stops before the body.
                    'progress' => function (int $expected, int $downloaded) use ($maxBytes, &$tooLarge): bool {
                        return $tooLarge = $expected > $maxBytes || $downloaded > $maxBytes;
                    },
                ])
                ->get($url);
        } catch (ConnectionException|TransferException $e) {
            if ($tooLarge) {
                throw new ResponseTooLargeException($this->tooLargeMessage($maxBytes), $maxBytes);
            }

            throw new FetchFailedException('Could not connect: '.$this->shortError($e->getMessage()));
        }
    }

    /**
     * The body, with the cap checked again for transports that don't report
     * progress (Http::fake() in tests).
     *
     * @throws FetchFailedException
     */
    protected function body(Response $response, int $maxBytes): string
    {
        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            throw new ResponseTooLargeException($this->tooLargeMessage($maxBytes), $maxBytes);
        }

        return $body;
    }

    /**
     * @return array{0: string, 1: string|null} the bare MIME type and charset
     */
    protected function parseContentType(string $header): array
    {
        $parts = array_map('trim', explode(';', strtolower($header)));
        $charset = null;

        foreach (array_slice($parts, 1) as $parameter) {
            if (str_starts_with($parameter, 'charset=')) {
                $charset = trim(substr($parameter, 8), "\"' ");
            }
        }

        return [$parts[0], $charset ?: null];
    }

    protected function tooLargeMessage(int $maxBytes): string
    {
        return 'The page is larger than the '.number_format($maxBytes / 1024).' KB limit.';
    }

    protected function shortError(string $message): string
    {
        // cURL errors carry the whole URL and a docs link; the first
        // sentence is the useful part.
        $message = (string) preg_replace('/\s*\(see https?:\/\/[^)]+\)/', '', $message);

        return mb_strimwidth(trim($message), 0, 200, '…');
    }
}
