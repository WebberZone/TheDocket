<?php

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Allow-list sanitiser for stored article HTML. Article markup comes from
 * arbitrary third-party pages and is rendered unescaped in the reader, so
 * everything not listed here goes: scripts, styles, event handlers, forms,
 * frames, embeds, inline SVG and every attribute that isn't named below.
 *
 * Unknown wrapper elements (div, span, section...) are unwrapped rather
 * than dropped, so their text survives; the explicitly dropped elements go
 * with everything inside them.
 */
class HtmlSanitiser
{
    /**
     * Allowed elements and the attributes each may keep.
     */
    public const ALLOWED = [
        'a' => ['href', 'title'],
        'abbr' => ['title'],
        'b' => [],
        'blockquote' => ['cite'],
        'br' => [],
        'caption' => [],
        'cite' => [],
        'code' => [],
        'dd' => [],
        'del' => [],
        'details' => [],
        'dl' => [],
        'dt' => [],
        'em' => [],
        'figcaption' => [],
        'figure' => [],
        'h1' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'h5' => [],
        'h6' => [],
        'hr' => [],
        'i' => [],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'ins' => [],
        'kbd' => [],
        'li' => [],
        'mark' => [],
        'ol' => ['start', 'reversed'],
        'p' => [],
        'pre' => [],
        'q' => ['cite'],
        's' => [],
        'samp' => [],
        'small' => [],
        'strong' => [],
        'sub' => [],
        'summary' => [],
        'sup' => [],
        'table' => [],
        'tbody' => [],
        'td' => ['colspan', 'rowspan'],
        'tfoot' => [],
        'th' => ['colspan', 'rowspan', 'scope'],
        'thead' => [],
        'time' => ['datetime'],
        'tr' => [],
        'u' => [],
        'ul' => [],
        'var' => [],
    ];

    /**
     * Removed together with their contents.
     */
    public const DROPPED = [
        'applet', 'audio', 'base', 'button', 'canvas', 'dialog', 'embed',
        'form', 'frame', 'frameset', 'head', 'iframe', 'input', 'link',
        'map', 'math', 'meta', 'nav', 'noscript', 'object', 'option',
        'param', 'script', 'select', 'source', 'style', 'svg', 'template',
        'textarea', 'title', 'track', 'video',
    ];

    protected ?HtmlSanitizer $sanitizer = null;

    public function sanitise(string $html): string
    {
        $this->sanitizer ??= new HtmlSanitizer($this->config());

        return trim($this->sanitizer->sanitize($html));
    }

    /**
     * Plain text from article HTML, with block elements on their own lines,
     * for the search index, word count and excerpt.
     */
    public static function toText(string $html): string
    {
        $html = (string) preg_replace('/<(br|hr)\b[^>]*>/i', "\n", $html);
        $html = (string) preg_replace(
            '/<\/(p|div|h[1-6]|li|dt|dd|blockquote|pre|tr|figure|figcaption|table|section|article|ul|ol)>/i',
            "$0\n",
            $html
        );

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Words in a block of text. Counts runs of letters and digits (with
     * inner apostrophes), which works for any script that uses spaces.
     */
    public static function wordCount(string $text): int
    {
        return (int) preg_match_all("/[\\p{L}\\p{N}]+(?:['’][\\p{L}]+)*/u", $text);
    }

    protected function config(): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig())
            // Unknown elements are unwrapped, keeping their text.
            ->defaultAction(HtmlSanitizerAction::Block)
            // The page was already capped at download; the sanitiser's own
            // 20 KB default would truncate most articles.
            ->withMaxInputLength(-1)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeLinks()
            // Localised images point at this app's own image route.
            ->allowRelativeMedias()
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('img', 'loading', 'lazy');

        foreach (self::ALLOWED as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }

        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }

        return $config;
    }
}
