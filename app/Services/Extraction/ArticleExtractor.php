<?php

namespace App\Services\Extraction;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use Throwable;

/**
 * Pulls the article out of a fetched page with readability.php, and the
 * metadata readability doesn't expose (language, published date) from the
 * page's own meta tags and JSON-LD.
 */
class ArticleExtractor
{
    public function extract(string $html, string $url, ?string $charset = null): ExtractedArticle
    {
        $html = $this->toUtf8($html, $charset);
        $meta = $this->metadata($html);

        $readability = new Readability(
            (new Configuration())
                ->setFixRelativeURLs(true)
                ->setOriginalURL($url)
        );

        try {
            $readability->parse($html);
            $content = $readability->getContent();
        } catch (ParseException) {
            $content = null;
        }

        $title = $this->clean($readability->getTitle()) ?? $meta['title'];

        return new ExtractedArticle(
            title: $title !== null ? mb_substr($title, 0, 1000) : null,
            author: $this->limit($this->clean($readability->getAuthor()) ?? $meta['author']),
            siteName: $this->limit($this->clean($readability->getSiteName()) ?? $meta['site_name']),
            excerpt: $this->clean($readability->getExcerpt()) ?? $meta['description'],
            leadImageUrl: $this->absoluteHttpUrl($this->clean($readability->getImage()) ?? $meta['image'], $url),
            html: $content !== null && trim(strip_tags($content, '<img>')) !== '' ? $content : null,
            language: $meta['language'],
            publishedAt: $meta['published_at'],
        );
    }

    /**
     * Convert the page to UTF-8 using the HTTP charset, else a <meta>
     * charset, else assume UTF-8 (falling back to Windows-1252 when the
     * bytes are not valid UTF-8, which covers most legacy pages).
     */
    public function toUtf8(string $html, ?string $charset): string
    {
        if ($charset === null && preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([a-z0-9_\-:.]+)/i', substr($html, 0, 4096), $match)) {
            $charset = $match[1];
        }

        $charset = strtolower(trim((string) $charset));

        if ($charset !== '' && ! in_array($charset, ['utf-8', 'utf8'], true)) {
            $encodings = array_map('strtolower', mb_list_encodings());

            if (in_array($charset, $encodings, true)) {
                return (string) mb_convert_encoding($html, 'UTF-8', $charset);
            }
        }

        if (! mb_check_encoding($html, 'UTF-8')) {
            return (string) mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }

        return $html;
    }

    /**
     * Metadata from <meta>, <html lang> and JSON-LD.
     *
     * @return array{title: string|null, author: string|null, site_name: string|null, description: string|null, image: string|null, language: string|null, published_at: CarbonImmutable|null}
     */
    protected function metadata(string $html): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $meta = fn (string ...$names): ?string => $this->firstMeta($xpath, $names);

        $jsonLd = $this->jsonLd($xpath);

        $titleNode = $this->first($xpath, '//title');

        $language = $this->attribute($xpath, '//html/@lang')
            ?? $meta('og:locale', 'content-language', 'language', 'dc.language');

        return [
            'title' => $meta('og:title', 'twitter:title') ?? $this->clean($titleNode?->textContent),
            'author' => $meta('author', 'article:author', 'dc.creator', 'parsely-author', 'sailthru.author'),
            'site_name' => $meta('og:site_name', 'application-name'),
            'description' => $meta('og:description', 'description', 'twitter:description'),
            'image' => $meta('og:image', 'og:image:url', 'twitter:image', 'twitter:image:src'),
            'language' => $this->normaliseLanguage($language),
            'published_at' => $this->parseDate(
                $meta('article:published_time', 'og:published_time', 'datepublished', 'date', 'dc.date', 'dc.date.issued', 'pubdate', 'parsely-pub-date', 'sailthru.date')
                ?? ($jsonLd['datePublished'] ?? null)
                ?? $this->attribute($xpath, '//time[@datetime][1]/@datetime')
            ),
        ];
    }

    /**
     * @param  list<string>  $names
     */
    protected function firstMeta(DOMXPath $xpath, array $names): ?string
    {
        $found = [];

        foreach ($xpath->query('//meta[@content]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $key = strtolower($node->getAttribute('property') ?: $node->getAttribute('name') ?: $node->getAttribute('itemprop') ?: $node->getAttribute('http-equiv'));

            if ($key === '') {
                continue;
            }

            $found[$key] ??= $this->clean($node->getAttribute('content'));
        }

        foreach ($names as $name) {
            if (($found[$name] ?? null) !== null) {
                return $found[$name];
            }
        }

        return null;
    }

    protected function attribute(DOMXPath $xpath, string $query): ?string
    {
        return $this->clean($this->first($xpath, $query)?->nodeValue);
    }

    protected function first(DOMXPath $xpath, string $query): ?DOMNode
    {
        $nodes = $xpath->query($query);

        return $nodes === false ? null : $nodes->item(0);
    }

    /**
     * The first JSON-LD object that looks like an article.
     *
     * @return array<string, mixed>
     */
    protected function jsonLd(DOMXPath $xpath): array
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $data = json_decode((string) $node->textContent, true);

            if (! is_array($data)) {
                continue;
            }

            $candidates = array_is_list($data) ? $data : [$data];

            if (isset($data['@graph']) && is_array($data['@graph'])) {
                $candidates = array_merge($candidates, $data['@graph']);
            }

            foreach ($candidates as $candidate) {
                if (is_array($candidate) && isset($candidate['datePublished']) && is_string($candidate['datePublished'])) {
                    return $candidate;
                }
            }
        }

        return [];
    }

    protected function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }

        // Guard against junk ("0000-00-00", far-future placeholders).
        if ($date->year < 1990 || $date->isAfter(now()->addDay())) {
            return null;
        }

        return $date;
    }

    /**
     * "en_GB" and "EN-gb" become "en-GB"; anything that isn't a plausible
     * BCP 47 tag is dropped.
     */
    protected function normaliseLanguage(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }

        $language = str_replace('_', '-', trim(explode(',', $language)[0]));

        if (! preg_match('/^([a-z]{2,3})(?:-([a-z0-9]{2,8}))?/i', $language, $match)) {
            return null;
        }

        $tag = strtolower($match[1]);

        if (isset($match[2])) {
            $tag .= '-'.(strlen($match[2]) === 2 ? strtoupper($match[2]) : $match[2]);
        }

        return $tag;
    }

    protected function absoluteHttpUrl(?string $url, string $base): ?string
    {
        if ($url === null) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = (parse_url($base, PHP_URL_SCHEME) ?: 'https').':'.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            if (! str_starts_with($url, '/')) {
                return null;
            }

            $url = parse_url($base, PHP_URL_SCHEME).'://'.parse_url($base, PHP_URL_HOST)
                .(parse_url($base, PHP_URL_PORT) ? ':'.parse_url($base, PHP_URL_PORT) : '')
                .$url;
        }

        return strlen($url) <= 2048 ? $url : null;
    }

    protected function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }

    protected function limit(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, 255);
    }
}
