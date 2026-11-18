<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Support;

defined('_JEXEC') or die;

/**
 * Turns editor HTML into the short description shown next to a link in llms.txt.
 *
 * Stripping tags is not enough. `<strong>CLUSTER</strong><br />GRAPPOLO` loses
 * its word boundaries, a YOOtheme layout leaves its JSON comment behind, and the
 * first paragraph of a landing page is usually a shouting title and a button.
 * This class keeps the boundaries, drops the layout and the calls to action and
 * picks the first paragraph that reads as a sentence.
 *
 * Pure PHP, no Joomla dependency: it is covered by plain unit tests.
 */
final class Description
{
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_META = 'meta';
    public const SOURCE_TEXT = 'text';
    public const SOURCES = [self::SOURCE_AUTO, self::SOURCE_META, self::SOURCE_TEXT];

    /** A paragraph shorter than this is treated as a heading or a label, not a description. */
    public const MIN_PARAGRAPH = 80;

    private const BLOCKS = 'p|div|section|article|header|footer|aside|nav|main|figure|figcaption|blockquote|pre|ul|ol|li|dl|dt|dd|table|thead|tbody|tfoot|tr|td|th|h[1-6]|hr|form|fieldset|address|details|summary';

    private const INLINE = 'strong|b|em|i|u|s|small|mark|span|a|code|sup|sub|abbr|cite|font|label';

    /** Openers of a call to action: the sentence invites to do something instead of describing. */
    private const CTA = '/^(?:prenota|prenotati|prenotazione|iscriviti|iscrizioni?|scopri|richiedi|contattaci|contatta|chiamaci|chiama|clicca|vai\s+a|vai\s+alla|leggi|acquista|prova|registrati|partecipa|scarica|invia|compila|unisciti|inizia|entra|book|sign\s+up|register|learn\s+more|read\s+more|find\s+out|contact\s+us|call\s+us|get\s+started|buy|join|download|click)\b/iu';

    /**
     * Text of $html with word boundaries kept, one block per line.
     *
     * A YOOtheme Builder page stores its layout as a JSON comment in the article
     * text. The comment, `<style>` and `<script>` are removed first, with their
     * content, and so are buttons and the links styled as one.
     */
    public static function blocks(string $html, bool $dropButtons = true): array
    {
        $html = self::expandLayout($html);
        $html = (string) preg_replace('/<!--.*?-->/su', ' ', $html);
        // An unterminated comment (a layout truncated by the editor) takes the rest of the text with it.
        $html = (string) preg_replace('/<!--.*$/su', ' ', $html);
        $html = (string) preg_replace('#<(script|style|noscript|svg|iframe|template|select|textarea)\b[^>]*>.*?</\1\s*>#isu', ' ', $html);

        if ($dropButtons) {
            $html = (string) preg_replace('#<button\b[^>]*>.*?</button\s*>#isu', ' ', $html);
            $html = (string) preg_replace('#<a\b[^>]*\bclass\s*=\s*["\'][^"\']*\b(?:uk-button|btn|button|wp-block-button)[^"\']*["\'][^>]*>.*?</a\s*>#isu', ' ', $html);
            // Plugin shortcodes with a body: {gallery}...{/gallery}.
            $html = (string) preg_replace('#\{([a-z][\w-]*)(?:\s[^{}]*)?\}.*?\{/\1\}#isu', ' ', $html);
        }

        // A line break and the end of a block both end a line of text.
        $html = (string) preg_replace('#<br\b[^>]*>#iu', "\n", $html);
        $html = (string) preg_replace('#</?(?:' . self::BLOCKS . ')\b[^>]*>#iu', "\n", $html);
        // Two inline elements side by side are two words: </strong><strong>.
        $html = (string) preg_replace('#(</(?:' . self::INLINE . ')\s*>)\s*(?=<(?:' . self::INLINE . ')\b)#iu', '$1 ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{A0}", "\u{200B}", "\r"], [' ', '', ''], $text);

        $lines = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** Builder elements that carry no reading material. */
    private const LAYOUT_SKIP = ['image', 'video', 'map', 'social', 'pagination', 'search', 'button', 'html', 'code', 'sublayout'];

    /** Fields of a builder element that hold text written by the editor. */
    private const LAYOUT_FIELDS = ['title', 'headline', 'content', 'text', 'meta', 'description', 'caption'];

    /**
     * Replaces a YOOtheme Builder layout comment with the text it holds.
     *
     * A page built with the builder is stored as `<!-- {"type":"layout",...} -->`.
     * When the article carries nothing else, the layout is the only text there
     * is, so it is read instead of being thrown away with the comment.
     */
    public static function expandLayout(string $html): string
    {
        if (!str_contains($html, '<!--')) {
            return $html;
        }

        return (string) preg_replace_callback('/<!--\s*(\{.*?\})\s*-->/su', static function (array $match): string {
            $data = json_decode($match[1], true);

            if (!is_array($data) || !isset($data['type'])) {
                return $match[0];
            }

            $out = [];
            self::walkLayout($data, $out, 0);

            return "\n" . implode("\n", $out) . "\n";
        }, $html);
    }

    /**
     * @param array<string, mixed> $node
     * @param string[] $out
     */
    private static function walkLayout(array $node, array &$out, int $depth): void
    {
        if ($depth > 40 || ($node['status'] ?? true) === false) {
            return;
        }

        if (in_array(strtolower((string) ($node['type'] ?? '')), self::LAYOUT_SKIP, true)) {
            return;
        }

        $props = is_array($node['props'] ?? null) ? $node['props'] : [];

        foreach (self::LAYOUT_FIELDS as $field) {
            $value = $props[$field] ?? $node[$field] ?? null;

            if (is_string($value) && trim($value) !== '' && !preg_match('/^\{[\w.]+\}$/', trim($value))) {
                $out[] = '<div>' . $value . '</div>';
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                self::walkLayout($child, $out, $depth + 1);
            }
        }
    }

    /** All the text on one line, with spaces where tags used to be. */
    public static function plain(string $html, bool $dropButtons = true): string
    {
        return implode(' ', self::blocks($html, $dropButtons));
    }

    /**
     * Whether a line is a title, a label or a call to action rather than prose.
     */
    public static function isNoise(string $line, array $extraPhrases = []): bool
    {
        $line = trim($line);

        if ($line === '') {
            return true;
        }

        $letters = (string) preg_replace('/[^\p{L}]+/u', '', $line);

        // A shouted title: "SCUOLA DI MUSICA CLUSTER | MILANO", "CORSI DI MUSICA PER ADULTI".
        if (mb_strlen($letters) >= 3 && $letters === mb_strtoupper($letters) && $letters !== mb_strtolower($letters)) {
            return true;
        }

        if (preg_match(self::CTA, $line) === 1) {
            return true;
        }

        foreach ($extraPhrases as $phrase) {
            $phrase = trim((string) $phrase);

            if ($phrase !== '' && mb_stripos($line, $phrase) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first paragraph of prose, cut at the end of a sentence.
     *
     * Order of preference: a paragraph of at least MIN_PARAGRAPH characters; failing
     * that the longest of the remaining lines that still ends like a sentence.
     * Titles written in capitals and calls to action never qualify. Returns an
     * empty string when nothing reads as a description: an empty description is
     * better than a menu.
     *
     * @param array<int, string> $extraPhrases Site specific openers to skip.
     */
    public static function excerpt(string $html, int $limit = 200, array $extraPhrases = []): string
    {
        $candidates = [];

        foreach (self::blocks($html) as $line) {
            if (!self::isNoise($line, $extraPhrases)) {
                $candidates[] = $line;
            }
        }

        if ($candidates === []) {
            return '';
        }

        $chosen = null;

        foreach ($candidates as $line) {
            if (mb_strlen($line) >= self::MIN_PARAGRAPH) {
                $chosen = $line;
                break;
            }
        }

        if ($chosen === null) {
            // Short lines only: accept one that is a sentence, otherwise nothing.
            foreach ($candidates as $line) {
                if (preg_match('/[.!?…]["»”)]?$/u', $line) === 1 && mb_strlen($line) >= 30) {
                    $chosen = $line;
                    break;
                }
            }
        }

        return $chosen === null ? '' : self::cut($chosen, $limit);
    }

    /**
     * Cuts at the last full stop that fits in $limit; without one, at a word, with an ellipsis.
     */
    public static function cut(string $text, int $limit = 200): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        // The last sentence that ends inside the limit and is long enough to
        // stand alone. "3.5" and "S.p.A." do not end one: a full stop counts
        // only when whitespace or the end of the text follows it.
        $end = null;

        if (preg_match_all('/[.!?…]["»”)]?(?=\s|$)/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$mark, $offset]) {
                $length = mb_strlen(substr($text, 0, $offset + strlen($mark)));

                if ($length > $limit) {
                    break;
                }

                if ($length >= 40) {
                    $end = $length;
                }
            }
        }

        if ($end !== null) {
            return trim(mb_substr($text, 0, $end));
        }

        $head = mb_substr($text, 0, $limit);
        $head = (string) preg_replace('/\s+\S*$/u', '', $head);

        return rtrim($head, " \t\n\r\0\x0B,;:-–—") . '…';
    }

    /** A meta description with its whitespace normalised and HTML removed. */
    public static function clean(string $value, int $limit = 0): string
    {
        $value = self::plain($value);

        return $limit > 0 ? self::cut($value, $limit) : $value;
    }

    /**
     * Picks the description of a page.
     *
     * @param string $source      One of self::SOURCES.
     * @param string[] $metas     Candidate meta descriptions in order of preference
     *                            (the article's own, then 4SEO's).
     * @param string[] $generic   Descriptions that describe the whole site and so
     *                            say nothing about this page; they are skipped.
     */
    public static function choose(string $source, array $metas, string $html, array $generic = [], int $limit = 200, array $extraPhrases = []): string
    {
        $normalize = static fn (string $v): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $v)));
        $skip = array_filter(array_map($normalize, $generic), static fn (string $v): bool => $v !== '');

        if ($source !== self::SOURCE_TEXT) {
            foreach ($metas as $meta) {
                $meta = self::clean((string) $meta);

                if ($meta !== '' && !in_array($normalize($meta), $skip, true)) {
                    return self::cut($meta, max($limit, 300));
                }
            }
        }

        return $source === self::SOURCE_META ? '' : self::excerpt($html, $limit, $extraPhrases);
    }
}
