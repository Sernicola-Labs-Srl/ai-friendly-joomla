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
    public const TYPES = ['Article', 'WebPage', 'AboutPage', 'ContactPage', 'FAQPage', 'Course', 'Person', 'Event', 'MusicEvent', 'Service', 'DefinedTerm', 'EducationalOccupationalProgram', 'CollectionPage'];

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
    public static function apply(array $graph, ContentItem $item, array $rule, string $home, callable $raw, array $options = []): array
    {
        $pageKey = null;
        $baseKey = null;
        $orgId = null;
        $url = null;
        $webDescription = '';

        foreach ($graph as $key => $node) {
            $id = is_array($node) ? (string) ($node['@id'] ?? '') : '';

            if ($id !== '' && str_ends_with($id, '#/schema/Organization/base')) {
                $orgId = $id;
            }

            // The address the visitor is on is the one to publish: Joomla's own
            // route for the article carries parameters such as lang=*.
            if ($id !== '' && (str_ends_with($id, '#/schema/WebPage/base') || str_ends_with($id, '#webpage'))) {
                $baseKey = $key;
                if (!empty($node['url'])) $url = self::plainUrl((string) $node['url']);
                $webDescription = trim((string) ($node['description'] ?? ''));
            }

            if ($id !== '' && str_ends_with($id, '/schema/com_content/article/' . (int) $item->id)) {
                $pageKey = $key;
            }
        }

        // The page node and the organization are Joomla's; without them there is
        // nothing to attach to, and nothing is authored from scratch.
        if ($pageKey === null || $baseKey === null || $orgId === null) {
            return $graph;
        }

        $url = $url ?? self::plainUrl(self::url($item));
        $type = $rule['type'];
        $stored = null;
        $read = static function () use (&$stored, $raw): array {
            return $stored ??= $raw();
        };
        $description = self::description($item, $webDescription, $options, $read);
        $page = $type === 'Article' ? $graph[$pageKey] : $graph[$baseKey];
        $page = self::describe($page, $item, $orgId, $type, $description);
        if ($type !== 'Article' && $description !== '' && $description !== $webDescription) {
            $page['description'] = $description;
        }
        $extra = [];

        switch ($type) {
            case 'FAQPage':
                $stored = $read();
                $faq = FaqExtractor::extract($stored['introtext'], $stored['fulltext'], $home);
                if (!$faq && count($rule['params']) >= 2) {
                    $faq = FaqExtractor::fromFields((array) ($stored['fields'] ?? []), $rule['params'][0], $rule['params'][1], $home, $rule['params'][2] ?? '');
                }

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
                $node = self::course($item, $orgId, $url, $description, $read(), $options);
                $page['mainEntity'] = ['@id' => $node['@id']];
                $extra[] = $node;
                break;

            case 'Service':
                $page['@type'] = 'WebPage';
                $node = self::service($item, $orgId, $url, $rule['params'], $description);
                $page['mainEntity'] = ['@id' => $node['@id']];
                $extra[] = $node;
                break;

            case 'Person':
                $page['@type'] = 'ProfilePage';
                $node = self::person($item, $orgId, $rule['params'][0] ?? '', $url, $description, $read(), $home);
                $page['mainEntity'] = ['@id' => $node['@id']];
                $extra[] = $node;
                break;

            case 'Event':
            case 'MusicEvent':
                $page['@type'] = 'WebPage';
                $node = self::event($item, $orgId, $graph, $url, $type, $description, $read(), $options);
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
                    'description' => $description,
                    'url' => $url,
                    'inDefinedTermSet' => ['@id' => $set['@id']],
                ];
                $page['mainEntity'] = ['@id' => $node['@id']];
                $extra[] = $node;
                $extra[] = $set;
                break;

            case 'EducationalOccupationalProgram':
                $fields = (array) ($read()['fields'] ?? []);
                $node = self::program($item, $orgId, $url, $description, $fields);
                $page['mainEntity'] = ['@id' => $node['@id']];
                $extra[] = $node;
                break;

            case 'CollectionPage':
                $page['@type'] = 'CollectionPage';
                $page['mainEntity'] = self::itemList($read(), $home, $options);
                break;

            default:
                // Article: only enriched.
                break;
        }

        if ($type === 'Article') {
            $page['isPartOf'] = ['@id' => $graph[$baseKey]['@id']];
            $graph[$pageKey] = self::clean($page);
        } else {
            foreach (['thumbnailUrl', 'image'] as $media) {
                if (empty($page[$media]) && !empty($graph[$pageKey][$media])) $page[$media] = $graph[$pageKey][$media];
            }
            $graph[$baseKey] = self::clean($page);
            $articleId = (string) ($graph[$pageKey]['@id'] ?? '');
            $graph = \Sernicola\Library\AiFriendly\Integration\CoreSchema::remap($graph, [$articleId => (string) $page['@id']]);
            unset($graph[$pageKey]);
        }

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
    private static function describe(array $page, ContentItem $item, string $orgId, string $type, string $description): array
    {
        if ($type === 'Article' || empty($page['name'])) $page['name'] = $item->title;

        if ($description !== '' && ($type === 'Article' || empty($page['description']))) {
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

    private static function description(ContentItem $item, string $webDescription, array $options, callable $raw): string
    {
        $text = trim((string) ($item->metadata['description'] ?? ''));
        if ($text !== '') return $text;
        $normalize = static fn (string $value): string => trim((string) preg_replace('/\s+/u', ' ', $value));
        $generic = preg_split('/\R/u', (string) ($options['schema_generic_descriptions'] ?? '')) ?: [];
        $generic[] = (string) ($options['site_meta_description'] ?? '');
        $normalized = array_map($normalize, $generic);
        if ($webDescription !== '' && !in_array($normalize($webDescription), $normalized, true)) return $webDescription;
        $stored = $raw();
        return HtmlText::schemaExcerpt((string) (($stored['introtext'] ?? '') ?: $item->body));
    }

    private static function course(ContentItem $item, string $orgId, string $url, string $description, array $stored, array $options): array
    {
        $fields = (array) ($stored['fields'] ?? []);
        $audience = [];
        if (self::field($fields, 'corso_pubblico') !== '') $audience['audienceType'] = self::field($fields, 'corso_pubblico');
        foreach (['corso_eta_min' => 'suggestedMinAge', 'corso_eta_max' => 'suggestedMaxAge'] as $field => $key) {
            if (is_numeric(self::field($fields, $field))) $audience[$key] = (int) self::field($fields, $field);
        }
        $price = self::field($fields, 'corso_prezzo');
        $instructors = [];
        foreach (preg_split('/[\s,;]+/', self::field($fields, 'corso_docenti')) ?: [] as $id) {
            if (!ctype_digit($id)) continue;
            $teacher = (new \Sernicola\Library\AiFriendly\Provider\JoomlaContentProvider())->find($id);
            if ($teacher && $teacher->published && $teacher->accessible) {
                $instructors[] = ['@id' => self::plainUrl(self::url($teacher)) . '#person'];
            }
        }
        return [
            '@type' => 'Course',
            '@id' => $url . '#course',
            'name' => $item->title,
            'description' => $description,
            'url' => $url,
            'provider' => ['@id' => $orgId],
            'inLanguage' => $item->language !== '*' ? $item->language : ($options['site_language'] ?? 'it-IT'),
            'image' => $item->metadata['featured_image'] ?? null,
            'educationalLevel' => self::field($fields, 'corso_livello'),
            'audience' => $audience ? ['@type' => 'PeopleAudience'] + $audience : null,
            'teaches' => self::lines(self::field($fields, 'corso_insegna')),
            'instructor' => $instructors,
            'educationalCredentialAwarded' => self::field($fields, 'corso_attestato'),
            'offers' => is_numeric(str_replace(',', '.', $price)) ? ['@type' => 'Offer', 'price' => str_replace(',', '.', $price), 'priceCurrency' => self::field($fields, 'corso_valuta') ?: 'EUR', 'category' => 'Tuition'] : null,
            'hasCourseInstance' => ['@type' => 'CourseInstance', 'courseMode' => $options['schema_course_mode'] ?? 'onsite', 'location' => ['@id' => rtrim((string) ($options['site_home'] ?? (parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/')), '/') . '/#place']],
        ];
    }

    private static function person(ContentItem $item, string $orgId, string $jobTitle, string $url, string $description, array $stored, string $home): array
    {
        $fields = (array) ($stored['fields'] ?? []);
        $links = array_values(array_filter(self::lines(self::field($fields, 'docente_link')), static fn (string $link): bool => (bool) filter_var($link, FILTER_VALIDATE_URL)));
        $image = $item->metadata['featured_image'] ?? '';
        if ($image === '' && preg_match('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', (string) ($stored['introtext'] ?? '') . (string) ($stored['fulltext'] ?? ''), $match)) {
            $image = HtmlText::absolute($match[1], $home);
        }
        return [
            '@type' => 'Person',
            '@id' => $url . '#person',
            'name' => HtmlText::plain($item->title),
            'description' => $description,
            'url' => $url,
            'jobTitle' => $jobTitle,
            'image' => $image,
            'worksFor' => ['@id' => $orgId],
            'knowsAbout' => self::lines(self::field($fields, 'docente_strumento')) ?: ($item->metadata['tags'] ?? []),
            'alumniOf' => array_map(static fn (string $name): array => ['@type' => 'EducationalOrganization', 'name' => $name], self::lines(self::field($fields, 'docente_formazione'))),
            'sameAs' => $links,
        ];
    }

    /** Optional rule parameters: service type, area served. */
    private static function service(ContentItem $item, string $orgId, string $url, array $params, string $description): array
    {
        return [
            '@type' => 'Service', '@id' => $url . '#service', 'url' => $url,
            'name' => HtmlText::plain($item->title), 'description' => $description,
            'serviceType' => $params[0] ?? '',
            'areaServed' => !empty($params[1]) ? ['@type' => 'Country', 'name' => $params[1]] : null,
            'provider' => ['@id' => $orgId],
            'image' => $item->metadata['featured_image'] ?? null,
        ];
    }

    /**
     * @param array<int, mixed> $graph
     */
    private static function event(ContentItem $item, string $orgId, array $graph, string $url, string $type, string $description, array $stored, array $options): ?array
    {
        $plain = HtmlText::plain($item->body);
        $category = (string) ($item->metadata['category'] ?? '');
        $hint = preg_match('/\b(19|20)\d{2}\b/', $category, $year) ? (int) $year[0] : null;
        $dates = EventDates::parse($plain, $hint, (string) ($options['schema_timezone'] ?? 'Europe/Rome'));

        if ($dates === null) {
            return null;
        }

        $org = [];

        foreach ($graph as $node) {
            if (is_array($node) && ($node['@id'] ?? '') === $orgId) {
                $org = $node;
            }
        }

        $location = null;
        foreach ($graph as $node) {
            if (is_array($node) && in_array('Place', (array) ($node['@type'] ?? []), true) && !empty($node['@id'])) {
                $location = ['@id' => $node['@id']];
                break;
            }
        }
        if ($location === null && !empty($org['address'])) {
            $location = ['@type' => 'Place', 'name' => $org['name'] ?? '', 'address' => $org['address']];
        }

        $fields = (array) ($stored['fields'] ?? []);
        $place = self::field($fields, 'evento_luogo');
        $address = self::field($fields, 'evento_indirizzo');
        if ($place === '') {
            $places = [];
            foreach (self::lines((string) ($options['schema_event_places'] ?? '')) as $line) {
                [$keyword, $name, $street, $postal, $city] = array_pad(array_map('trim', explode('|', $line, 5)), 5, '');
                if ($keyword !== '' && $name !== '') $places[] = compact('keyword', 'name', 'street', 'postal', 'city');
            }
            foreach ([$item->title, mb_substr($plain, 0, 500)] as $haystack) {
                foreach ($places as $candidate) {
                    if (mb_stripos($haystack, $candidate['keyword']) === false) continue;
                    $place = $candidate['name'];
                    $address = implode('|', [$candidate['street'], $candidate['postal'], $candidate['city']]);
                    break 2;
                }
            }
        }
        if ($place !== '') {
            [$street, $postal, $city] = array_pad(array_map('trim', explode('|', $address, 3)), 3, '');
            $location = ['@type' => 'Place', 'name' => $place];
            if ($street !== '' || $postal !== '' || $city !== '') {
                $location['address'] = self::clean(['@type' => 'PostalAddress', 'streetAddress' => $street, 'postalCode' => $postal, 'addressLocality' => $city]);
            }
        }

        return [
            '@type' => $type,
            '@id' => $url . '#event',
            'name' => $item->title,
            'description' => $description,
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

    private static function program(ContentItem $item, string $orgId, string $url, string $description, array $fields): array
    {
        $price = self::field($fields, 'corso_prezzo');
        return self::clean([
            '@type' => 'EducationalOccupationalProgram', '@id' => $url . '#program',
            'name' => $item->title, 'description' => $description, 'url' => $url,
            'provider' => ['@id' => $orgId],
            'programPrerequisites' => self::field($fields, 'programma_prerequisiti'),
            'timeToComplete' => self::field($fields, 'programma_durata'),
            'educationalCredentialAwarded' => self::field($fields, 'programma_attestato'),
            'numberOfCredits' => self::field($fields, 'programma_crediti'),
            'offers' => is_numeric(str_replace(',', '.', $price)) ? ['@type' => 'Offer', 'price' => str_replace(',', '.', $price), 'priceCurrency' => self::field($fields, 'corso_valuta') ?: 'EUR'] : null,
        ]);
    }

    private static function itemList(array $stored, string $home, array $options): ?array
    {
        $html = (string) ($stored['introtext'] ?? '') . (string) ($stored['fulltext'] ?? '');
        preg_match_all('/<a\b[^>]*\bhref=["\']([^"\']+)["\']/i', $html, $matches);
        $links = [];
        $rules = self::rules((string) ($options['schema_page_rules'] ?? ''));
        $provider = null;
        foreach ($matches[1] ?? [] as $href) {
            $url = HtmlText::absolute(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $home);
            if ($url !== '' && str_starts_with($url, $home) && !isset($links[$url])) {
                $provider ??= new \Sernicola\Library\AiFriendly\Provider\JoomlaContentProvider();
                $target = $provider->resolve((string) parse_url($url, PHP_URL_PATH));
                if (!$target || !$target->published || !$target->accessible || !empty($target->metadata['noindex'])) continue;
                $rule = self::match($rules, (int) $target->id, self::categoryChain($target));
                if (!in_array($rule['type'] ?? '', ['Course', 'Person'], true)) continue;
                $links[$url] = ['@type' => 'ListItem', 'position' => count($links) + 1,
                    'item' => ['@id' => self::plainUrl(self::url($target)) . (($rule['type'] === 'Person') ? '#person' : '#course')]];
            }
        }
        return $links ? ['@type' => 'ItemList', 'itemListElement' => array_values($links)] : null;
    }

    private static function field(array $fields, string $name): string
    {
        $value = $fields[$name] ?? '';
        return trim(is_scalar($value) ? (string) $value : '');
    }

    /** Match parent category rules for linked articles as well as direct ones. */
    private static function categoryChain(ContentItem $item): array
    {
        $ids = array_map('intval', (array) ($item->taxonomies['category_ids'] ?? []));
        if (!$ids) return [];
        $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');
        $current = $ids[0];
        for ($depth = 0; $depth < 20 && $current > 1; $depth++) {
            $query = $db->getQuery(true)->select($db->quoteName('parent_id'))
                ->from($db->quoteName('#__categories'))->where($db->quoteName('id') . ' = ' . $current);
            $parent = (int) $db->setQuery($query)->loadResult();
            if ($parent <= 0 || in_array($parent, $ids, true)) break;
            $ids[] = $parent;
            $current = $parent;
        }
        return $ids;
    }

    private static function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $value) ?: []), static fn (string $line): bool => $line !== ''));
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
