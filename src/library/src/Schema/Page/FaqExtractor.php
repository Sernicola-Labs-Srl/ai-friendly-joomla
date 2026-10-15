<?php

namespace Sernicola\Library\AiFriendly\Schema\Page;

defined('_JEXEC') or die;

/**
 * Finds the questions and answers of a page.
 *
 * The YOOtheme builder keeps the layout as JSON inside an HTML comment of the
 * article; that is the source of truth, and it works whatever the page renders.
 * Plain accordion and <details> markup in the article text is the fallback for
 * pages written without the builder.
 */
final class FaqExtractor
{
    /**
     * @return array<int, array{question:string, answer:string}>
     */
    public static function extract(string $introtext, string $fulltext, string $home): array
    {
        $text = $introtext . "\n" . $fulltext;
        $pairs = self::fromBuilder($text);

        if (!$pairs) {
            $pairs = self::fromMarkup($text);
        }

        $seen = [];
        $faq = [];

        foreach ($pairs as [$question, $answer]) {
            $question = HtmlText::plain($question);
            $answer = HtmlText::sanitize($answer, $home);

            if ($question === '' || HtmlText::plain($answer) === '' || isset($seen[mb_strtolower($question)])) {
                continue;
            }

            $seen[mb_strtolower($question)] = true;
            $faq[] = ['question' => $question, 'answer' => $answer];
        }

        return $faq;
    }

    /**
     * @return array<int, array{0:string, 1:string}>
     */
    private static function fromBuilder(string $text): array
    {
        $offset = 0;
        $pairs = [];

        while (($start = strpos($text, '<!--', $offset)) !== false) {
            $brace = strpos($text, '{', $start);

            if ($brace === false || trim(substr($text, $start + 4, $brace - $start - 4)) !== '') {
                $offset = $start + 4;
                continue;
            }

            $data = null;
            $end = $brace;

            // A comment ends at the first "-->" after which the JSON is complete.
            while (($end = strpos($text, '-->', $end)) !== false) {
                $decoded = json_decode(substr($text, $brace, $end - $brace), true);

                if (is_array($decoded)) {
                    $data = $decoded;
                    break;
                }

                $end += 3;
            }

            if ($data === null) {
                $offset = $start + 4;
                continue;
            }

            self::walk($data, $pairs);
            $offset = $end + 3;
        }

        return $pairs;
    }

    /**
     * @param array<int, array{0:string, 1:string}> $pairs
     */
    private static function walk($node, array &$pairs, int $depth = 0): void
    {
        if (!is_array($node) || $depth > 60 || ($node['status'] ?? true) === false) {
            return;
        }

        if (($node['type'] ?? '') === 'accordion_item') {
            $props = (array) ($node['props'] ?? []);

            if (($props['status'] ?? true) !== false) {
                $title = (string) ($props['title'] ?? '');
                $content = (string) ($props['content'] ?? '');

                if ($title !== '' && $content !== '') {
                    $pairs[] = [$title, $content];
                }
            }

            return;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                self::walk($child, $pairs, $depth + 1);
            }
        }
    }

    /**
     * @return array<int, array{0:string, 1:string}>
     */
    private static function fromMarkup(string $text): array
    {
        $text = (string) preg_replace('/<!--.*?-->/s', '', $text);

        if (!str_contains($text, 'uk-accordion') && !str_contains($text, '<details')) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="utf-8"?><div>' . $text . '</div>', LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $pairs = [];

        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " el-item ")]') ?: [] as $item) {
            $title = $xpath->query('.//*[contains(@class, "uk-accordion-title") or contains(@class, "el-title")]', $item)->item(0);
            $content = $xpath->query('.//*[contains(@class, "el-content")]', $item)->item(0);

            if ($title && $content) {
                $pairs[] = [self::inner($title), self::inner($content)];
            }
        }

        foreach ($xpath->query('//details') ?: [] as $details) {
            $summary = $xpath->query('./summary', $details)->item(0);

            if (!$summary) {
                continue;
            }

            $body = '';
            foreach ($details->childNodes as $child) {
                if ($child !== $summary) {
                    $body .= $dom->saveHTML($child);
                }
            }

            $pairs[] = [self::inner($summary), $body];
        }

        return $pairs;
    }

    private static function inner(\DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }

        return $html;
    }
}
