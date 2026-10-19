<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Provider;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Sernicola\Library\AiFriendly\Contract\ContentProviderInterface;
use Sernicola\Library\AiFriendly\Dto\ContentItem;
use Sernicola\Library\AiFriendly\Support\Options;
use Sernicola\Library\AiFriendly\Support\Path;
use Sernicola\Library\AiFriendly\Support\SiteRoute;

/**
 * Exposes com_contact entries.
 *
 * Contact records hold personal data, so a field is published only when the
 * site already shows it on the public page: every value is gated on the same
 * show_* parameter com_contact uses, resolved per contact and then globally.
 *
 * Email addresses are gated twice. Joomla cloaks them on the front end
 * specifically to frustrate harvesters, and writing them into a Markdown file
 * would undo that, so they also require the contact_include_email option,
 * which is off by default.
 */
final class JoomlaContactProvider implements ContentProviderInterface
{
    private $db;
    private ?\Joomla\Registry\Registry $globalParams = null;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
    }

    public function name(): string
    {
        return 'contact';
    }

    public function list(array $filters = [], int $limit = 100, int $offset = 0): iterable
    {
        $query = $this->baseQuery();

        if (!empty($filters['language'])) {
            $query->where($this->db->quoteName('a.language') . ' IN (' . $this->db->quote($filters['language']) . ', ' . $this->db->quote('*') . ')');
        }
        if (!empty($filters['search'])) {
            $query->where($this->db->quoteName('a.name') . ' LIKE ' . $this->db->quote('%' . $filters['search'] . '%'));
        }
        if (!empty($filters['category_id'])) {
            $query->where($this->db->quoteName('a.catid') . ' = ' . (int) $filters['category_id']);
        }

        $query->order($this->db->quoteName('a.ordering') . ' ASC, ' . $this->db->quoteName('a.id') . ' ASC');

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
        if ($id = SiteRoute::itemId($path, 'com_contact', 'contact')) {
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

        if ($language) {
            $query->where($this->db->quoteName('a.language') . ' IN (' . $this->db->quote($language) . ', ' . $this->db->quote('*') . ')');
        }

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
            $query->where($this->db->quoteName('a.name') . ' LIKE ' . $this->db->quote('%' . $filters['search'] . '%'));
        }

        return (int) $this->db->setQuery($query)->loadResult();
    }

    private function baseQuery()
    {
        $now = Factory::getDate()->toSql();

        return $this->db->getQuery(true)
            ->select(['a.*', $this->db->quoteName('c.title', 'category_title')])
            ->from($this->db->quoteName('#__contact_details', 'a'))
            ->leftJoin($this->db->quoteName('#__categories', 'c') . ' ON c.id = a.catid')
            ->where($this->db->quoteName('a.published') . ' = 1')
            ->where('(' . $this->db->quoteName('a.publish_up') . ' IS NULL OR ' . $this->db->quoteName('a.publish_up') . ' <= ' . $this->db->quote($now) . ')')
            ->where('(' . $this->db->quoteName('a.publish_down') . ' IS NULL OR ' . $this->db->quoteName('a.publish_down') . ' = ' . $this->db->quote($this->db->getNullDate()) . ' OR ' . $this->db->quoteName('a.publish_down') . ' >= ' . $this->db->quote($now) . ')');
    }

    private function hydrate(object $contact): ?ContentItem
    {
        $route = Route::link(
            'site',
            'index.php?option=com_contact&view=contact&id=' . (int) $contact->id . '&catid=' . (int) $contact->catid . '&lang=' . rawurlencode((string) $contact->language),
            true,
            Route::TLS_IGNORE,
            true
        );

        if (!str_starts_with($route, 'http')) {
            $route = rtrim(Uri::root(), '/') . '/' . ltrim($route, '/');
        }

        $params = json_decode((string) ($contact->params ?? ''), true) ?: [];
        $metadata = json_decode((string) ($contact->metadata ?? ''), true) ?: [];
        $groups = \Sernicola\Library\AiFriendly\Support\ViewLevels::current();

        return new ContentItem(
            'contact',
            'contact',
            (string) $contact->id,
            (string) ($contact->language ?: '*'),
            (string) $contact->name,
            $route,
            Path::normalize(parse_url($route, PHP_URL_PATH) ?: '/'),
            $this->body($contact, $params),
            $this->excerpt($contact, $params),
            [
                'description' => (string) ($contact->metadesc ?? ''),
                'category' => (string) ($contact->category_title ?? ''),
                'noindex' => str_contains(strtolower((string) ($metadata['robots'] ?? '')), 'noindex'),
                'access_level' => (int) $contact->access,
            ],
            ['category_ids' => [(int) $contact->catid], 'tag_ids' => []],
            (string) ($contact->modified ?: $contact->created),
            true,
            in_array((int) $contact->access, $groups, true)
        );
    }

    /**
     * Builds the readable body from the fields the site publishes.
     */
    private function body(object $contact, array $params): string
    {
        $parts = [];

        if ($position = $this->visible($contact, $params, 'position', 'con_position')) {
            $parts[] = '<p>' . htmlspecialchars($position, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $address = array_filter([
            $this->visible($contact, $params, 'street_address', 'address'),
            $this->visible($contact, $params, 'suburb', 'suburb'),
            $this->visible($contact, $params, 'postcode', 'postcode'),
            $this->visible($contact, $params, 'state', 'state'),
            $this->visible($contact, $params, 'country', 'country'),
        ]);

        $rows = [];

        if ($address) {
            $rows[] = ['Address', implode(', ', $address)];
        }
        foreach ([['telephone', 'telephone'], ['mobile', 'mobile'], ['fax', 'fax'], ['webpage', 'webpage']] as [$show, $column]) {
            if ($value = $this->visible($contact, $params, $show, $column)) {
                $rows[] = [ucfirst($show), $value];
            }
        }
        if ($email = $this->email($contact, $params)) {
            $rows[] = ['Email', $email];
        }

        foreach ($rows as [$label, $value]) {
            $parts[] = '<p><strong>' . $label . ':</strong> ' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        if ($misc = $this->visible($contact, $params, 'misc', 'misc')) {
            $parts[] = $misc;
        }

        return implode("\n", $parts);
    }

    /**
     * Returns the column value only when com_contact would display it.
     */
    private function visible(object $contact, array $params, string $show, string $column): string
    {
        $value = trim((string) ($contact->{$column} ?? ''));

        if ($value === '' || !$this->shows($params, 'show_' . $show)) {
            return '';
        }

        return $value;
    }

    /**
     * Email needs the display parameter and an explicit opt-in: publishing a
     * cloaked address as plain text would defeat the cloaking.
     */
    private function email(object $contact, array $params): string
    {
        if (empty(Options::get()['contact_include_email'])) {
            return '';
        }

        return $this->visible($contact, $params, 'email', 'email_to');
    }

    /**
     * Per-contact parameter, falling back to the component configuration when
     * the contact is set to inherit.
     */
    private function shows(array $params, string $key): bool
    {
        $value = $params[$key] ?? '';

        if ($value === '' || $value === null) {
            $this->globalParams ??= ComponentHelper::getParams('com_contact');
            $value = $this->globalParams->get($key, 0);
        }

        return (string) $value === '1';
    }

    private function excerpt(object $contact, array $params): string
    {
        $text = trim((string) ($contact->metadesc ?? ''));

        if ($text === '') {
            $text = trim(strip_tags((string) $this->visible($contact, $params, 'misc', 'misc')));
        }
        if ($text === '') {
            $text = trim((string) $this->visible($contact, $params, 'position', 'con_position'));
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strlen($text) > 180 ? mb_substr($text, 0, 179) . '…' : $text;
    }
}
