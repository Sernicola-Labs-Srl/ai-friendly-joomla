<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Schema\Page;

defined('_JEXEC') or die;

/**
 * Small, dependency-free helpers to turn editor HTML into text fit for JSON-LD.
 */
final class HtmlText
{
    /** Tags a schema.org Answer may carry. */
    private const ALLOWED = ['p', 'br', 'a', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'h2', 'h3', 'h4'];

    /** Tags removed together with everything inside them. */
    private const DROPPED = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'noscript'];

    public static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Keeps basic formatting and links, drops everything else, and makes
     * relative links absolute so the text still works outside the site.
     */
    public static function sanitize(string $html, string $home): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="aif-root">' . $html . '</div>', LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('aif-root');

        if (!$root) {
            return self::plain($html);
        }

        self::clean($root, $home);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        $out = (string) preg_replace('/>\s+</u', '><', $out);
        $out = str_replace("\xC2\xA0", ' ', $out);

        return trim((string) preg_replace('/[ \t\r\n]+/u', ' ', $out));
    }

    private static function clean(\DOMNode $node, string $home): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROPPED, true)) {
                $node->removeChild($child);
                continue;
            }

            self::clean($child, $home);

            if (!in_array($tag, self::ALLOWED, true)) {
                // Unwrap: keep the content, lose the element.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $child->removeAttributeNode($attribute);
            }

            if ($tag === 'a') {
                $href = self::absolute($href, $home);

                if ($href === '') {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                $child->setAttribute('href', $href);
            }
        }
    }

    public static function absolute(string $url, string $home): string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '#') || preg_match('/^(javascript|data|vbscript):/i', $url)) {
            return '';
        }

        if (preg_match('#^(https?:)?//#i', $url) || preg_match('/^(mailto|tel):/i', $url)) {
            return str_starts_with($url, '//') ? 'https:' . $url : $url;
        }

        return rtrim($home, '/') . '/' . ltrim($url, '/');
    }
}
