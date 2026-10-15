<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Schema;

defined('_JEXEC') or die;

use Sernicola\Library\AiFriendly\Dto\ContentItem;
use Sernicola\Library\AiFriendly\Schema\Page\EventDates;
use Sernicola\Library\AiFriendly\Schema\Page\FaqExtractor;
use Sernicola\Library\AiFriendly\Schema\Page\HtmlText;

/**
 * Gives each article the schema.org type that describes it.
 *
 * Joomla publishes every article as a generic Article. The site states, with
 * rules, what its pages really are: a question page, a course, a teacher, an
 * event, a glossary term. The rules live in one option, one per line:
 *
 *     article:43=FAQPage
 *     category:24=Course
 *     category:23=Person|Insegnante
 *     article:55=Person|Direttrice
 *     category:39=DefinedTerm|Glossario di Cluster|https://example.com/glossario.html
 *
 * `article:` matches one article, `category:` a category and all its children.
 * The first matching line wins and article rules are read before category ones.
 * Nothing is invented: a page that lacks what its type needs keeps its plain
 * WebPage.
 */
final class PageSchema
{
    public const TYPES = ['Article', 'WebPage', 'AboutPage', 'ContactPage', 'FAQPage', 'Course', 'Person', 'Event', 'DefinedTerm'];

    /**
     * @return array<int, array{scope:string, id:int, type:string, params:array<int, string>}>
     */
    public static function rules(string $text): array
    {
        $rules = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (!preg_match('/^\s*(article|category)\s*:\s*(\d+)\s*=\s*([A-Za-z]+)\s*(?:\|(.*))?$/u', $line, $m)) {
                continue;
            }

            if (!in_array($m[3], self::TYPES, true)) {
                continue;
            }

            $params = isset($m[4]) ? array_map('trim', explode('|', $m[4])) : [];
            $rules[] = ['scope' => $m[1], 'id' => (int) $m[2], 'type' => $m[3], 'params' => $params];
        }

        // Article rules first, whatever their position in the text.
        usort($rules, static fn (array $a, array $b): int => ($a['scope'] === 'article' ? 0 : 1) <=> ($b['scope'] === 'article' ? 0 : 1));

        return $rules;
    }

    /**
     * @param array<int, array{scope:string, id:int, type:string, params:array<int, string>}> $rules
     * @param array<int, int> $categoryIds The article's category followed by its ancestors.
     * @return array{scope:string, id:int, type:string, params:array<int, string>}|null
     */
    public static function match(array $rules, int $articleId, array $categoryIds): ?array
    {
        foreach ($rules as $rule) {
            if ($rule['scope'] === 'article' && $rule['id'] === $articleId) {
                return $rule;
            }

            if ($rule['scope'] === 'category' && in_array($rule['id'], $categoryIds, true)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $graph Joomla's graph, already carrying the identity.
     * @param array{scope:string, id:int, type:string, params:array<int, string>} $rule
     * @param callable():array{introtext:string, fulltext:string} $raw Reads the stored article text, only when a type needs it.
     * @return array<int, mixed>
     */
    public static function apply(array $graph, ContentItem $item, array $rule, string $home, callable $raw): array
    {
        $pageKey = null;
        $orgId = null;
        $url = null;

        foreach ($graph as $key => $node) {
            $id = is_array($node) ? (string) ($node['@id'] ?? '') : '';

            if ($id !== '' && str_ends_with($id, '#/schema/Organization/base')) {
                $orgId = $id;
            }

            // The address the visitor is on is the one to publish: Joomla's own
            // route for the article carries parameters such as lang=*.
            if ($id !== '' && str_ends_with($id, '#/schema/WebPage/base') && !empty($node['url'])) {
                $url = self::plainUrl((string) $node['url']);
            }

            if ($id !== '' && str_ends_with($id, '/schema/com_content/article/' . (int) $item->id)) {
                $pageKey = $key;
            }
        }

        // The page node and the organization are Joomla's; without them there is
        // nothing to attach to, and nothing is authored from scratch.
        if ($pageKey === null || $orgId === null) {
            return $graph;
        }

        $url = $url ?? self::plainUrl(self::url($item));
        $type = $rule['type'];
        $page = $graph[$pageKey];
        $page = self::describe($page, $item, $orgId, $type);
        $extra = [];

        switch ($type) {
            case 'FAQPage':
                $stored = $raw();
                $faq = FaqExtractor::extract($stored['introtext'], $stored['fulltext'], $home);

                if ($faq) {
                    $page['mainEntity'] = array_map(static fn (array $qa): array => [
                        '@type' => 'Question',
                        'name' => $qa['question'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa['answer']],
                    ], $faq);
                    $page['@type'] = 'FAQPage';
                } else {
                    $page['@type'] = 'WebPage';
                }
                break;

            case 'AboutPage':
            case 'ContactPage':
            case 'WebPage':
                $page['@type'] = $type;
                break;

            case 'Course':
                $page['@type'] = 'WebPage';
                $node = self::course($item, $orgId, $url);
                $page['mainEntity'] = ['@id' => $node['@id']];
                $extra[] = $node;
                break;

            case 'Person':
                $page['@type'] = 'ProfilePage';
                $node = self::person($item, $orgId, $rule['params'][0] ?? '', $url);
                $page['mainEntity'] = ['@id' => $node['@id']];
                $extra[] = $node;
                break;

            case 'Event':
                $page['@type'] = 'WebPage';
                $node = self::event($item, $orgId, $graph, $url);
                if ($node) {
                    $page['mainEntity'] = ['@id' => $node['@id']];
                    $extra[] = $node;
                }
                break;

            case 'DefinedTerm':
                $page['@type'] = 'WebPage';
                $set = [
                    '@type' => 'DefinedTermSet',
                    '@id' => rtrim($home, '/') . '/#glossary',
                    'name' => $rule['params'][0] ?? '' ?: 'Glossario',
                    'url' => $rule['params'][1] ?? '',
                    'publisher' => ['@id' => $orgId],
                ];
                $node = [
                    '@type' => 'DefinedTerm',
                    '@id' => $url . '#term',
                    'name' => $item->title,
                    'description' => self::description($item),
                    'url' => $url,
                    'inDefinedTermSet' => ['@id' => $set['@id']],
                ];
                $page['mainEntity'] = ['@id' => $node['@id']];
                $extra[] = $node;
                $extra[] = $set;
                break;

            default:
                // Article: only enriched.
                break;
        }

        $graph[$pageKey] = self::clean($page);

        foreach ($extra as $node) {
            $node = self::clean($node);
            $existing = null;

            foreach ($graph as $key => $current) {
                if (is_array($current) && ($current['@id'] ?? null) === ($node['@id'] ?? '')) {
                    $existing = $key;
                }
            }

            if ($existing === null) {
                $graph[] = $node;
            } else {
                $graph[$existing] = $node;
            }
        }

        return array_values($graph);
    }

    /**
     * What every type shares: dates, image, and who stands behind the page.
     */
    private static function describe(array $page, ContentItem $item, string $orgId, string $type): array
    {
        $page['name'] = $item->title;
        $description = self::description($item);

        if ($description !== '') {
            $page['description'] = $description;
        }

        if ($type !== 'Article') {
            unset($page['headline']);
        }

        $date = (string) ($item->metadata['date'] ?? '');
        $modified = (string) ($item->metadata['modified'] ?? '');

        if ($date !== '') {
            $page['datePublished'] = $date;
        }

        if ($modified !== '') {
            $page['dateModified'] = $modified;
        }

        if (($item->metadata['featured_image'] ?? '') !== '') {
            $page['image'] = $item->metadata['featured_image'];
        }

        $page['publisher'] = ['@id' => $orgId];

        if ($type === 'Article') {
            $page['author'] = ['@id' => $orgId];
        }

        return $page;
    }

    /** The URL without query string or fragment, entities decoded. */
    private static function plainUrl(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cut = strcspn($url, '?#');

        return substr($url, 0, $cut);
    }

    /** Joomla hands the route over HTML-escaped ("&amp;"); JSON-LD needs the plain URL. */
    private static function url(ContentItem $item): string
    {
        return html_entity_decode($item->canonicalUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function description(ContentItem $item): string
    {
        $text = trim((string) ($item->metadata['description'] ?? ''));

        return $text !== '' ? $text : HtmlText::plain($item->excerpt);
    }

    private static function course(ContentItem $item, string $orgId, string $url): array
    {
        return [
            '@type' => 'Course',
            '@id' => $url . '#course',
            'name' => $item->title,
            'description' => self::description($item),
            'url' => $url,
            'provider' => ['@id' => $orgId],
            'inLanguage' => ($item->language ?? '*') !== '*' ? $item->language : null,
            'image' => $item->metadata['featured_image'] ?? null,
        ];
    }

    private static function person(ContentItem $item, string $orgId, string $jobTitle, string $url): array
    {
        return [
            '@type' => 'Person',
            '@id' => $url . '#person',
            'name' => HtmlText::plain($item->title),
            'description' => self::description($item),
            'url' => $url,
            'jobTitle' => $jobTitle,
            'image' => $item->metadata['featured_image'] ?? null,
            'worksFor' => ['@id' => $orgId],
        ];
    }

    /**
     * @param array<int, mixed> $graph
     */
    private static function event(ContentItem $item, string $orgId, array $graph, string $url): ?array
    {
        $plain = HtmlText::plain($item->body);
        $category = (string) ($item->metadata['category'] ?? '');
        $hint = preg_match('/\b(19|20)\d{2}\b/', $category, $year) ? (int) $year[0] : null;
        $dates = EventDates::parse($plain, $hint);

        if ($dates === null) {
            return null;
        }

        $org = [];

        foreach ($graph as $node) {
            if (is_array($node) && ($node['@id'] ?? '') === $orgId) {
                $org = $node;
            }
        }

        $location = stripos($plain, 'teatro pime') !== false
            ? ['@type' => 'Place', 'name' => 'Teatro PIME', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $org['address']['addressLocality'] ?? '', 'addressCountry' => $org['address']['addressCountry'] ?? '']]
            : ['@type' => 'Place', 'name' => $org['name'] ?? '', 'address' => $org['address'] ?? null];

        return [
            '@type' => 'Event',
            '@id' => $url . '#event',
            'name' => $item->title,
            'description' => self::description($item),
            'url' => $url,
            'startDate' => $dates['start'],
            'endDate' => $dates['end'],
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => $location,
            'organizer' => ['@id' => $orgId],
            'image' => $item->metadata['featured_image'] ?? null,
        ];
    }

    private static function clean($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            $item = self::clean($item);

            if ($item === '' || $item === null || $item === []) {
                continue;
            }

            $clean[$key] = $item;
        }

        return array_is_list($value) ? array_values($clean) : $clean;
    }
}
