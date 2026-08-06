<?php

namespace Sernicola\Library\AiFriendly\Markdown;

defined('_JEXEC') or die;

use Sernicola\Library\AiFriendly\Support\Path;

final class Converter
{
    private const NOISE = ['read more', 'leggi di più', 'show more', 'mostra di più', 'click here', 'clicca qui'];

    /** Blocks that carry no reading material. */
    private const DROP_BLOCKS = 'script|style|noscript|svg|template|iframe|form|nav|aside|button';

    public function __construct(
        private readonly bool $normalizeHeadings = true,
        private readonly string $siteRoot = '',
        private readonly bool $linkToMarkdown = false,
    ) {
    }

    public function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $html = preg_replace('#<(' . self::DROP_BLOCKS . ')[^>]*>.*?</\1>#si', '', $html) ?? $html;
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = $this->codeBlocks($html);
        $html = $this->headings($html);
        $html = $this->blockquotes($html);
        $html = $this->media($html);
        $html = $this->anchors($html);
        $html = $this->inline($html);

        // Lists and tables run after the inline pass so their content is already
        // Markdown: converting them first would strip the links and emphasis
        // they contain.
        $html = $this->definitionLists($html);
        $html = $this->lists($html);
        $html = $this->tables($html);

        $html = preg_replace('#<hr\s*/?>#i', "\n\n---\n\n", $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</p>#i', "\n\n", $html) ?? $html;
        $html = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $html) ?? $html;

        // Whitespace cleanup has to spare the indentation of nested list items,
        // which is meaningful Markdown rather than stray formatting.
        $html = preg_replace('/(?<=\S)[^\S\n]+/', ' ', $html) ?? $html;
        $html = preg_replace('/[^\S\n]+$/m', '', $html) ?? $html;
        // Possessive: allowing backtracking here would let the quantifier give
        // spaces back one at a time until the guard passes, eating indentation.
        $html = preg_replace('/^[^\S\n]++(?![-*+]\s|\d+\.\s)/m', '', $html) ?? $html;
        $html = preg_replace('/\n{3,}/', "\n\n", $html) ?? $html;

        return trim($html);
    }

    private function headings(string $html): string
    {
        $shift = $this->normalizeHeadings && preg_match('/<h1\b/i', $html) ? 1 : 0;

        return preg_replace_callback('#<h([1-6])[^>]*>(.*?)</h\1>#si', static function (array $m) use ($shift): string {
            $level = min(6, (int) $m[1] + $shift);
            $text = trim(strip_tags($m[2]));

            return $text === '' ? '' : "\n\n" . str_repeat('#', $level) . ' ' . $text . "\n\n";
        }, $html) ?? $html;
    }

    private function blockquotes(string $html): string
    {
        return preg_replace_callback('#<blockquote[^>]*>(.*?)</blockquote>#si', static function (array $m): string {
            $text = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return $text === '' ? '' : "\n\n> " . str_replace("\n", "\n> ", $text) . "\n\n";
        }, $html) ?? $html;
    }

    private function media(string $html): string
    {
        $html = preg_replace_callback('#<img\b[^>]*>#i', function (array $m): string {
            $src = $this->absolute($this->attribute($m[0], 'src'));
            $alt = $this->attribute($m[0], 'alt');

            return $src === '' || $alt === '' || preg_match('/pixel|spacer|spinner|favicon/i', $src) ? '' : "![{$alt}]({$src})";
        }, $html) ?? $html;

        return preg_replace_callback('#<figcaption[^>]*>(.*?)</figcaption>#si', static function (array $m): string {
            $text = trim(strip_tags($m[1]));

            return $text === '' ? '' : "\n*" . $text . "*\n";
        }, $html) ?? $html;
    }

    private function anchors(string $html): string
    {
        return preg_replace_callback('#<a\b[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)</a>#si', function (array $m): string {
            $url = trim($m[1]);
            $text = trim(strip_tags($m[2]));

            if ($url === '' || str_starts_with($url, '#') || preg_match('/^javascript:/i', $url)) {
                return $text;
            }

            if ($this->isNoise($text)) {
                return '';
            }

            $url = $this->markdownTarget($this->absolute($url));

            return $text === '' ? $url : "[{$text}]({$url})";
        }, $html) ?? $html;
    }

    /**
     * Resolves a URL against the site root.
     *
     * A relative link is meaningless once the document is read outside the
     * site, which is exactly how an AI client consumes it.
     */
    private function absolute(string $url): string
    {
        $url = trim($url);

        if ($url === '' || $this->siteRoot === '') {
            return $url;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return $url;
        }

        $origin = rtrim($this->siteRoot, '/');

        if (str_starts_with($url, '//')) {
            $scheme = parse_url($this->siteRoot, PHP_URL_SCHEME) ?: 'https';

            return $scheme . ':' . $url;
        }

        return $origin . '/' . ltrim($url, '/');
    }

    /**
     * Points an internal link at its Markdown twin when asked to.
     *
     * Only clean page routes qualify: anything with a query string, or ending
     * in a file extension, has no Markdown counterpart and would 404.
     */
    private function markdownTarget(string $url): string
    {
        if (!$this->linkToMarkdown || $this->siteRoot === '' || !str_starts_with($url, rtrim($this->siteRoot, '/') . '/')) {
            return $url;
        }

        $parts = parse_url($url);

        if (!empty($parts['query']) || !empty($parts['fragment'])) {
            return $url;
        }

        // Only clean routes and Joomla SEF .html pages have a Markdown twin;
        // a .pdf or .jpg would turn into a 404.
        $extension = strtolower(pathinfo((string) ($parts['path'] ?? ''), PATHINFO_EXTENSION));

        if ($extension !== '' && !in_array($extension, ['html', 'htm'], true)) {
            return $url;
        }

        return Path::markdownUrl($url);
    }

    private function inline(string $html): string
    {
        $html = preg_replace('#<code[^>]*>(.*?)</code>#si', '`$1`', $html) ?? $html;
        $html = preg_replace('#<(strong|b)[^>]*>(.*?)</\1>#si', '**$2**', $html) ?? $html;
        $html = preg_replace('#<(em|i)[^>]*>(.*?)</\1>#si', '*$2*', $html) ?? $html;

        // <mark> is a highlight with no Markdown equivalent; keep the words.
        return preg_replace('#</?mark[^>]*>#i', '', $html) ?? $html;
    }

    /**
     * Markdown has no portable definition list, so each pair becomes a bold
     * term followed by its definition, which reads correctly everywhere.
     */
    private function definitionLists(string $html): string
    {
        return preg_replace_callback('#<dl[^>]*>(.*?)</dl>#si', static function (array $m): string {
            preg_match_all('#<(dt|dd)\b[^>]*>(.*?)(?=</\1>|<dt\b|<dd\b|$)#si', $m[1], $pairs, PREG_SET_ORDER);
            $lines = [];

            foreach ($pairs as $pair) {
                $text = trim(preg_replace('/\s+/u', ' ', strip_tags($pair[2])) ?? '');

                if ($text === '') {
                    continue;
                }

                if (strtolower($pair[1]) === 'dt') {
                    $lines[] = '**' . $text . '**';
                } elseif ($lines) {
                    $lines[count($lines) - 1] .= ': ' . $text;
                } else {
                    $lines[] = $text;
                }
            }

            return $lines ? "\n\n" . implode("\n\n", $lines) . "\n\n" : '';
        }, $html) ?? $html;
    }

    /**
     * Converts lists innermost first, so an already-converted nested list is
     * indented under the item that contains it. Ordered lists keep their
     * numbering instead of collapsing to bullets.
     */
    private function lists(string $html): string
    {
        $guard = 0;

        while ($guard++ < 100 && preg_match('#<(ul|ol)\b[^>]*>((?:(?!<(?:ul|ol)\b).)*?)</\1>#is', $html, $match)) {
            $html = str_replace($match[0], "\n\n" . $this->listItems($match[2], strtolower($match[1])) . "\n\n", $html);
        }

        // Anything left is a malformed list; drop the tags and keep the text.
        return preg_replace('#</?(?:ul|ol)[^>]*>#i', '', $html) ?? $html;
    }

    private function listItems(string $inner, string $type): string
    {
        preg_match_all('#<li\b[^>]*>(.*?)(?=</li>|<li\b|$)#si', $inner, $items);
        $lines = [];
        $number = 1;

        foreach ($items[1] as $content) {
            $marker = $type === 'ol' ? $number++ . '. ' : '- ';
            $rows = preg_split('/\R/', trim($content)) ?: [];
            $rows = array_values(array_filter(array_map('trim', $rows), static fn (string $row): bool => $row !== ''));

            if (!$rows) {
                continue;
            }

            $line = $marker . array_shift($rows);

            foreach ($rows as $row) {
                $line .= "\n" . str_repeat(' ', mb_strlen($marker)) . $row;
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function codeBlocks(string $html): string
    {
        return preg_replace_callback('#<pre[^>]*>(?:<code[^>]*>)?(.*?)(?:</code>)?</pre>#si', static function (array $m): string {
            $code = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return "\n```\n" . trim($code) . "\n```\n";
        }, $html) ?? $html;
    }

    private function tables(string $html): string
    {
        return preg_replace_callback('#<table[^>]*>(.*?)</table>#si', static function (array $m): string {
            preg_match_all('#<tr[^>]*>(.*?)</tr>#si', $m[1], $rows);
            $output = [];

            foreach ($rows[1] as $index => $row) {
                preg_match_all('#<(?:th|td)[^>]*>(.*?)</(?:th|td)>#si', $row, $cells);
                $values = array_map(
                    // A cell must stay on one line, and a pipe would split it.
                    static fn (string $cell): string => str_replace('|', '\\|', trim(preg_replace('/\s+/u', ' ', strip_tags($cell)) ?? '')),
                    $cells[1]
                );

                if (!$values) {
                    continue;
                }

                $output[] = '| ' . implode(' | ', $values) . ' |';

                if ($index === 0) {
                    $output[] = '| ' . implode(' | ', array_fill(0, count($values), '---')) . ' |';
                }
            }

            return $output ? "\n\n" . implode("\n", $output) . "\n\n" : '';
        }, $html) ?? $html;
    }

    private function attribute(string $tag, string $name): string
    {
        return preg_match('/\b' . preg_quote($name, '/') . '=["\']([^"\']*)["\']/i', $tag, $match) ? $match[1] : '';
    }

    private function isNoise(string $text): bool
    {
        return in_array(mb_strtolower(trim($text)), self::NOISE, true);
    }
}
