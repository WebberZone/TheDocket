<?php

namespace Tests\Unit;

use App\Exceptions\InvalidUrlException;
use App\Services\UrlNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlNormaliserTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function equivalentUrls(): array
    {
        return [
            'case of scheme and host' => ['HTTPS://Example.COM/Path', 'https://example.com/Path'],
            'default port' => ['https://example.com:443/a', 'https://example.com/a'],
            'default http port' => ['http://example.com:80/a', 'http://example.com/a'],
            'non-default port kept' => ['https://example.com:8443/a', 'https://example.com:8443/a'],
            'fragment' => ['https://example.com/a#comments', 'https://example.com/a'],
            'empty path' => ['https://example.com', 'https://example.com/'],
            'tracking parameters' => ['https://example.com/a?utm_source=x&utm_medium=y&fbclid=z&gclid=1&id=7', 'https://example.com/a?id=7'],
            'only tracking parameters' => ['https://example.com/a?utm_campaign=autumn', 'https://example.com/a'],
            'query order' => ['https://example.com/a?b=2&a=1', 'https://example.com/a?a=1&b=2'],
            'percent-escape case' => ['https://example.com/a%2fb?q=%c3%a9', 'https://example.com/a%2Fb?q=%C3%A9'],
            'trailing dot on host' => ['https://example.com./a', 'https://example.com/a'],
            'user info dropped' => ['https://user:pass@example.com/a', 'https://example.com/a'],
            'missing scheme' => ['example.com/article', 'https://example.com/article'],
            'surrounding whitespace' => ["  https://example.com/a \n", 'https://example.com/a'],
            'international domain' => ['https://bücher.example/', 'https://xn--bcher-kva.example/'],
            'trailing slash is significant' => ['https://example.com/a/', 'https://example.com/a/'],
        ];
    }

    #[DataProvider('equivalentUrls')]
    public function test_normalises(string $input, string $expected): void
    {
        $this->assertSame($expected, (new UrlNormaliser())->normalise($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidUrls(): array
    {
        return [
            'empty' => [''],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'mailto' => ['mailto:someone@example.com'],
            'ftp' => ['ftp://example.com/file'],
            'file' => ['file:///etc/passwd'],
            'no host' => ['https://'],
            'spaces in host' => ['not a url'],
            'too long' => ['https://example.com/'.str_repeat('a', 2100)],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_rejects(string $input): void
    {
        $this->expectException(InvalidUrlException::class);

        (new UrlNormaliser())->normalise($input);
    }
}
