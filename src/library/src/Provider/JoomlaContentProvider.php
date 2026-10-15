<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Provider;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Sernicola\Library\AiFriendly\Contract\ContentProviderInterface;
use Sernicola\Library\AiFriendly\Dto\ContentItem;
use Sernicola\Library\AiFriendly\Support\Options;
use Sernicola\Library\AiFriendly\Support\Path;
use Sernicola\Library\AiFriendly\Support\SiteRoute;

final class JoomlaContentProvider implements ContentProviderInterface
{
    private $db;

    /** @var array<int, array{parent:int, robots:string}>|null Loaded once per instance. */
    private ?array $categories = null;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
    }

    public function name(): string
    {
        return 'joomla';
    }

    public function list(array $filters = [], int $limit = 100, int $offset = 0): iterable
    {
        $query = $this->baseQuery();
        if (!empty($filters['language'])) {
            $query->where($this->db->quoteName('a.language') . ' IN (' . $this->db->quote($filters['language']) . ', ' . $this->db->quote('*') . ')');
        }
        if (!empty($filters['search'])) {
            $query->where($this->db->quoteName('a.title') . ' LIKE ' . $this->db->quote('%' . $filters['search'] . '%'));
        }
        if (!empty($filters['category_id'])) {
            $query->where($this->db->quoteName('a.catid') . ' = ' . (int) $filters['category_id']);
        }
        if (!empty($filters['category_ids']) && is_array($filters['category_ids'])) {
            $query->where($this->db->quoteName('a.catid') . ' IN (' . implode(',', array_map('intval', $filters['category_ids'])) . ')');
        }
        $query->order($this->db->quoteName('a.publish_up') . ' DESC, ' . $this->db->quoteName('a.id') . ' DESC');
        $rows = $this->db->setQuery($query, max(0, $offset), max(1, $limit))->loadObjectList() ?: [];

        foreach ($rows as $row) {
            $item = $this->hydrate($row);
            if ($item) {
                yield $item;
            }
        }
    }

    public function resolve(string $path, ?string $language = null): ?ContentItem
    {
        $path = Path::normalize($path);
        if ($path === '/') {
            $menu = Factory::getApplication()->getMenu('site');
            $default = $language ? $menu->getDefault($language) : $menu->getDefault();
            if ($default && ($default->query['option'] ?? '') === 'com_content' && ($default->query['view'] ?? '') === 'article') {
                return $this->find((string) ($default->query['id'] ?? ''), $language);
            }
        }

        // The router is authoritative: a SEF path is usually built from the menu
        // structure, so the segment is often a menu alias rather than the
        // article's own.
        if ($id = SiteRoute::itemId($path, 'com_content', 'article')) {
            if ($item = $this->find($id, $language)) {
                return $item;
            }
        }

        $query = $this->baseQuery()
            ->where($this->db->quoteName('a.alias') . ' = ' . $this->db->quote(Path::alias($path)));
        if ($language) {
            $query->where($this->db->quoteName('a.language') . ' IN (' . $this->db->quote($language) . ', ' . $this->db->quote('*') . ')');
        }
        $rows = $this->db->setQuery($query)->loadObjectList() ?: [];
        // Compared without the SEF suffix so both /page.md and /page.html.md
        // reach the same article whatever the site's URL configuration.
        $wanted = Path::withoutSefSuffix($path);
        foreach ($rows as $row) {
            $item = $this->hydrate($row);
            if ($item && Path::withoutSefSuffix(parse_url($item->canonicalUrl, PHP_URL_PATH) ?: $item->routePath) === $wanted) {
                return $item;
            }
        }

        return null;
    }

    public function find(string $id, ?string $language = null): ?ContentItem
    {
        if (!ctype_digit($id)) {
            return null;
        }
        $query = $this->baseQuery()->where($this->db->quoteName('a.id') . ' = ' . (int) $id);
        if ($language) {
            $query->where($this->db->quoteName('a.language') . ' IN (' . $this->db->quote($language) . ', ' . $this->db->quote('*') . ')');
        }

        return ($row = $this->db->setQuery($query)->loadObject()) ? $this->hydrate($row) : null;
    }

    public function count(array $filters = []): int
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__content', 'a'))
            ->where($this->db->quoteName('a.state') . ' = 1');
        if (!empty($filters['language'])) {
            $query->where($this->db->quoteName('a.language') . ' IN (' . $this->db->quote($filters['language']) . ', ' . $this->db->quote('*') . ')');
        }

        return (int) $this->db->setQuery($query)->loadResult();
    }

    public function categoryByPath(string $path, ?string $language = null): ?object
    {
        $alias = Path::alias($path);
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_content'))
            ->where($this->db->quoteName('published') . ' = 1')
            ->where($this->db->quoteName('alias') . ' = ' . $this->db->quote($alias));
        if ($language) {
            $query->where($this->db->quoteName('language') . ' IN (' . $this->db->quote($language) . ', ' . $this->db->quote('*') . ')');
        }

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    private function baseQuery()
    {
        $now = Factory::getDate()->toSql();
        return $this->db->getQuery(true)
            ->select(['a.*', $this->db->quoteName('c.title', 'category_title'), $this->db->quoteName('u.name', 'author_name')])
            ->from($this->db->quoteName('#__content', 'a'))
            ->leftJoin($this->db->quoteName('#__categories', 'c') . ' ON c.id = a.catid')
            ->leftJoin($this->db->quoteName('#__users', 'u') . ' ON u.id = a.created_by')
            ->where($this->db->quoteName('a.state') . ' = 1')
            ->where('(' . $this->db->quoteName('a.publish_up') . ' IS NULL OR ' . $this->db->quoteName('a.publish_up') . ' <= ' . $this->db->quote($now) . ')')
            ->where('(' . $this->db->quoteName('a.publish_down') . ' IS NULL OR ' . $this->db->quoteName('a.publish_down') . ' = ' . $this->db->quote($this->db->getNullDate()) . ' OR ' . $this->db->quoteName('a.publish_down') . ' >= ' . $this->db->quote($now) . ')');
    }

    private function hydrate(object $article): ?ContentItem
    {
        $route = Route::link('site', 'index.php?option=com_content&view=article&id=' . (int) $article->id . '&catid=' . (int) $article->catid . '&lang=' . rawurlencode((string) $article->language), true, Route::TLS_IGNORE, true);
        if (!str_starts_with($route, 'http')) {
            $route = rtrim(Uri::root(), '/') . '/' . ltrim($route, '/');
        }
        $images = json_decode((string) ($article->images ?? ''), true) ?: [];
        $featured = $images['image_fulltext'] ?? $images['image_intro'] ?? '';
        if ($featured !== '' && !str_starts_with($featured, 'http')) {
            $featured = rtrim(Uri::root(), '/') . '/' . ltrim($featured, '/');
        }
        $tags = $this->tags((int) $article->id);
        $fields = $this->customFields((int) $article->id);
        $body = $this->prepareText(trim((string) ($article->introtext ?? '') . "\n" . (string) ($article->fulltext ?? '')), $article);
        if ($fields !== '') {
            $body .= "\n\n" . $fields;
        }
        $groups = Factory::getApplication()->getIdentity()->getAuthorisedViewLevels();
        $attribs = json_decode((string) ($article->attribs ?? ''), true) ?: [];
        $metadata = json_decode((string) ($article->metadata ?? ''), true) ?: [];

        return new ContentItem(
            'joomla', 'article', (string) $article->id, (string) ($article->language ?: '*'),
            (string) $article->title, $route, Path::normalize(parse_url($route, PHP_URL_PATH) ?: '/'), $body,
            $this->excerpt((string) ($article->metadesc ?: $article->introtext)),
            [
                'description' => (string) ($article->metadesc ?? ''),
                'featured_image' => $featured,
                'date' => substr((string) ($article->publish_up ?: $article->created), 0, 10),
                'modified' => substr((string) ($article->modified ?: $article->created), 0, 10),
                'author' => (string) ($article->author_name ?? ''),
                'category' => (string) ($article->category_title ?? ''),
                'tags' => array_column($tags, 'title'),
                'noindex' => $this->isNoindex($metadata, $attribs, (int) $article->catid),
            ],
            ['category_ids' => [(int) $article->catid], 'tag_ids' => array_map('intval', array_column($tags, 'id'))],
            (string) ($article->modified ?: $article->created), true, in_array((int) $article->access, $groups, true)
        );
    }

    /**
     * Resolves the effective robots setting, most specific first.
     *
     * The article wins when it states anything at all, so an explicit
     * "index, follow" still overrides a noindex category. Otherwise the value
     * is inherited from the category branch.
     *
     * The global configuration is deliberately NOT applied: a staging site set
     * to noindex globally would silently produce an empty llms.txt. That case
     * is surfaced as a diagnostic warning instead.
     */
    private function isNoindex(array $metadata, array $attribs, int $categoryId): bool
    {
        $own = strtolower(trim((string) ($metadata['robots'] ?? $attribs['robots'] ?? '')));

        if ($own !== '') {
            return str_contains($own, 'noindex');
        }

        return str_contains($this->categoryRobots($categoryId), 'noindex');
    }

    /**
     * Robots value inherited from the category or, failing that, from its
     * closest ancestor that sets one.
     */
    private function categoryRobots(int $categoryId): string
    {
        if ($this->categories === null) {
            $this->categories = $this->loadCategories();
        }

        $seen = [];
        $current = $categoryId;

        while ($current > 0 && isset($this->categories[$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            if ($this->categories[$current]['robots'] !== '') {
                return $this->categories[$current]['robots'];
            }
            $current = $this->categories[$current]['parent'];
        }

        return '';
    }

    /**
     * Loads every content category once: hydrating a batch of articles would
     * otherwise issue a query per article to walk the same tree.
     *
     * @return array<int, array{parent:int, robots:string}>
     */
    private function loadCategories(): array
    {
        $query = $this->db->getQuery(true)
            ->select([$this->db->quoteName('id'), $this->db->quoteName('parent_id'), $this->db->quoteName('params')])
            ->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_content'));

        $categories = [];

        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $params = json_decode((string) ($row['params'] ?? ''), true) ?: [];
            $robots = strtolower(trim((string) ($params['robots'] ?? '')));
            $categories[(int) $row['id']] = ['parent' => (int) $row['parent_id'], 'robots' => $robots];
        }

        return $categories;
    }

    /**
     * Runs the article text through Joomla content plugins so shortcodes and
     * plugin output are resolved instead of reaching the Markdown verbatim.
     *
     * Module tags are stripped first, and unconditionally: rendered modules are
     * navigation and banners, which are noise for an AI reader, and unrendered
     * they would leak {loadposition ...} into the output.
     */
    private function prepareText(string $text, object $article): string
    {
        $text = (string) preg_replace('/\{load(?:position|module|moduleid)\s[^}]*\}/i', '', $text);

        if ($text === '' || empty(Options::get()['render_content_plugins'])) {
            return trim($text);
        }

        try {
            PluginHelper::importPlugin('content');
            $holder = (object) ['text' => $text, 'id' => $article->id ?? 0, 'catid' => $article->catid ?? 0];
            $params = new Registry();
            Factory::getApplication()->triggerEvent('onContentPrepare', ['com_content.article', &$holder, &$params, 0]);
            $text = (string) $holder->text;
        } catch (\Throwable) {
            // A misbehaving content plugin must not stop the article being served.
        }

        return trim($text);
    }

    private function tags(int $articleId): array
    {
        $query = $this->db->getQuery(true)
            ->select(['t.id', 't.title'])
            ->from($this->db->quoteName('#__tags', 't'))
            ->innerJoin($this->db->quoteName('#__contentitem_tag_map', 'm') . ' ON m.tag_id = t.id')
            ->where($this->db->quoteName('m.content_item_id') . ' = ' . $articleId)
            ->where($this->db->quoteName('m.type_alias') . ' = ' . $this->db->quote('com_content.article'));

        return $this->db->setQuery($query)->loadAssocList() ?: [];
    }

    private function customFields(int $articleId): string
    {
        $query = $this->db->getQuery(true)
            ->select(['f.title', 'v.value'])
            ->from($this->db->quoteName('#__fields_values', 'v'))
            ->innerJoin($this->db->quoteName('#__fields', 'f') . ' ON f.id = v.field_id')
            ->where($this->db->quoteName('v.item_id') . ' = ' . $articleId)
            ->where($this->db->quoteName('f.context') . ' = ' . $this->db->quote('com_content.article'))
            ->where($this->db->quoteName('f.state') . ' = 1')
            ->order($this->db->quoteName('f.ordering'));
        $rows = $this->db->setQuery($query)->loadObjectList() ?: [];
        $output = [];
        foreach ($rows as $row) {
            $value = is_string($row->value) ? trim($row->value) : '';
            if ($value === '' || preg_match('/^(?:https?:\/\/|\/).*\.(?:jpe?g|png|gif|svg|webp|mp4|pdf)(?:\?.*)?$/i', $value)) {
                continue;
            }
            $output[] = '<h2>' . htmlspecialchars((string) $row->title, ENT_QUOTES, 'UTF-8') . '</h2><p>' . $value . '</p>';
        }

        return implode("\n", $output);
    }

    private function excerpt(string $html, int $limit = 180): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
    }
}

