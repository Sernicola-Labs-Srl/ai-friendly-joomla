<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Provider;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Sernicola\Library\AiFriendly\Contract\ContentProviderInterface;
use Sernicola\Library\AiFriendly\Dto\ContentItem;
use Sernicola\Library\AiFriendly\Support\Lang;
use Sernicola\Library\AiFriendly\Support\Path;
use Sernicola\Library\AiFriendly\Support\SiteRoute;

/**
 * Exposes com_tags pages as collections.
 *
 * A tag page is navigational: its value for a reader is the description plus
 * the list of what carries the tag, so the body is built from the tagged
 * articles rather than from the tag record alone.
 */
final class JoomlaTagProvider implements ContentProviderInterface
{
    /** Tagged entries listed per tag page. */
    private const MAX_ITEMS = 50;

    private $db;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
    }

    public function name(): string
    {
        return 'tag';
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

        $query->order($this->db->quoteName('a.lft') . ' ASC');

        foreach ($this->db->setQuery($query, max(0, $offset), max(1, $limit))->loadObjectList() ?: [] as $row) {
            if ($item = $this->hydrate($row)) {
                yield $item;
            }
        }
    }

    public function resolve(string $path, ?string $language = null): ?ContentItem
    {
        $path = Path::normalize($path);
        // Strips the numeric prefix and the SEF suffix Joomla may append.
        $alias = Path::alias($path);

        if ($alias === '' || $alias === '/') {
            return null;
        }

        // The router knows the menu structure; the alias lookup is the fallback.
        if ($id = SiteRoute::itemId($path, 'com_tags', 'tag')) {
            if ($item = $this->find($id, $language)) {
                return $item;
            }
        }

        $query = $this->baseQuery()->where($this->db->quoteName('a.alias') . ' = ' . $this->db->quote($alias));

        if ($language) {
            $query->where($this->db->quoteName('a.language') . ' IN (' . $this->db->quote($language) . ', ' . $this->db->quote('*') . ')');
        }

        // Compared without the SEF suffix so /page.md and /page.html.md agree.
        $wanted = Path::withoutSefSuffix($path);

        foreach ($this->db->setQuery($query, 0, 5)->loadObjectList() ?: [] as $row) {
            $item = $this->hydrate($row);
            if ($item && Path::withoutSefSuffix($item->routePath) === $wanted) {
                return $item;
            }
        }

        return null;
    }

    public function find(string $id, ?string $language = null): ?ContentItem
    {
        $query = $this->baseQuery()->where($this->db->quoteName('a.id') . ' = ' . (int) $id);
        $row = $this->db->setQuery($query)->loadObject();

        return $row ? $this->hydrate($row) : null;
    }

    public function count(array $filters = []): int
    {
        $query = $this->baseQuery();
        $query->clear('select')->select('COUNT(*)');

        if (!empty($filters['language'])) {
            $query->where($this->db->quoteName('a.language') . ' IN (' . $this->db->quote($filters['language']) . ', ' . $this->db->quote('*') . ')');
        }
        if (!empty($filters['search'])) {
            $query->where($this->db->quoteName('a.title') . ' LIKE ' . $this->db->quote('%' . $filters['search'] . '%'));
        }

        return (int) $this->db->setQuery($query)->loadResult();
    }

    private function baseQuery()
    {
        return $this->db->getQuery(true)
            ->select('a.*')
            ->from($this->db->quoteName('#__tags', 'a'))
            ->where($this->db->quoteName('a.published') . ' = 1')
            // The nested-set root is a container, not a real tag.
            ->where($this->db->quoteName('a.parent_id') . ' > 0');
    }

    private function hydrate(object $tag): ?ContentItem
    {
        $route = Route::link('site', 'index.php?option=com_tags&view=tag&id[0]=' . (int) $tag->id . '&lang=' . rawurlencode((string) $tag->language), true, Route::TLS_IGNORE, true);

        if (!str_starts_with($route, 'http')) {
            $route = rtrim(Uri::root(), '/') . '/' . ltrim($route, '/');
        }

        $items = $this->taggedItems((int) $tag->id);

        if (!$items && trim(strip_tags((string) ($tag->description ?? ''))) === '') {
            return null;
        }

        $metadata = json_decode((string) ($tag->metadata ?? ''), true) ?: [];
        $groups = \Sernicola\Library\AiFriendly\Support\ViewLevels::current();

        return new ContentItem(
            'tag',
            'tag',
            (string) $tag->id,
            (string) ($tag->language ?: '*'),
            (string) $tag->title,
            $route,
            Path::normalize(parse_url($route, PHP_URL_PATH) ?: '/'),
            $this->body($tag, $items),
            $this->excerpt($tag, count($items)),
            [
                'description' => (string) ($tag->metadesc ?? ''),
                'noindex' => str_contains(strtolower((string) ($metadata['robots'] ?? '')), 'noindex'),
                'access_level' => (int) $tag->access,
            ],
            ['category_ids' => [], 'tag_ids' => [(int) $tag->id]],
            (string) ($tag->modified_time ?: $tag->created_time),
            true,
            in_array((int) $tag->access, $groups, true)
        );
    }

    /**
     * @param array<int, object> $items
     */
    private function body(object $tag, array $items): string
    {
        $parts = [];

        if ($description = trim((string) ($tag->description ?? ''))) {
            $parts[] = $description;
        }

        if ($items) {
            $list = '';
            foreach ($items as $item) {
                $url = Route::link('site', 'index.php?option=com_content&view=article&id=' . (int) $item->id . '&catid=' . (int) $item->catid, true, Route::TLS_IGNORE, true);
                if (!str_starts_with($url, 'http')) {
                    $url = rtrim(Uri::root(), '/') . '/' . ltrim($url, '/');
                }
                $list .= '<li><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $item->title, ENT_QUOTES, 'UTF-8') . '</a></li>';
            }
            $parts[] = '<ul>' . $list . '</ul>';
        }

        return implode("\n", $parts);
    }

    /**
     * Published articles carrying the tag, capped so a heavily used tag does
     * not produce an unbounded document.
     *
     * @return array<int, object>
     */
    private function taggedItems(int $tagId): array
    {
        $now = Factory::getDate()->toSql();

        $query = $this->db->getQuery(true)
            ->select([$this->db->quoteName('c.id'), $this->db->quoteName('c.title'), $this->db->quoteName('c.catid')])
            ->from($this->db->quoteName('#__contentitem_tag_map', 'm'))
            ->innerJoin($this->db->quoteName('#__content', 'c') . ' ON c.id = m.content_item_id')
            ->where($this->db->quoteName('m.tag_id') . ' = ' . $tagId)
            ->where($this->db->quoteName('m.type_alias') . ' = ' . $this->db->quote('com_content.article'))
            ->where($this->db->quoteName('c.state') . ' = 1')
            ->where('(' . $this->db->quoteName('c.publish_up') . ' IS NULL OR ' . $this->db->quoteName('c.publish_up') . ' <= ' . $this->db->quote($now) . ')')
            ->order($this->db->quoteName('c.publish_up') . ' DESC');

        return $this->db->setQuery($query, 0, self::MAX_ITEMS)->loadObjectList() ?: [];
    }

    private function excerpt(object $tag, int $count): string
    {
        $text = trim((string) ($tag->metadesc ?? ''));

        if ($text === '') {
            $text = trim(strip_tags((string) ($tag->description ?? '')));
        }
        if ($text === '') {
            $text = Lang::sprintf('PLG_SYSTEM_AIFRIENDLY_TAG_ITEM_COUNT', '%s items', (string) $count);
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strlen($text) > 180 ? mb_substr($text, 0, 179) . '…' : $text;
    }
}
