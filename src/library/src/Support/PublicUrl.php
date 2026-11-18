<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Support;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

/**
 * The address a visitor actually uses for a page.
 *
 * Joomla's router builds the native address of an article: menu path, numeric
 * id and alias (`/corsi-cluster/1226-corsi-di-musica-per-adulti.html`). A site
 * that runs a third-party SEF component publishes another one, and the native
 * address may answer 404 or redirect. The component keeps the public address
 * in its own table, and this class reads it from there.
 *
 * Supported tables, detected at run time:
 *
 *  - forSEF:    `#__forsef_urls`   (`sef`, `nonsef`, `duplicate`)
 *  - sh404SEF:  `#__sh404sef_urls` (`oldurl`, `newurl`, `rank`)
 *
 * 4SEO has no router of its own: it builds on Joomla's, so there is nothing to
 * look up for it. With none of these installed the answer is the address
 * Joomla built, which is what the plugin published before.
 */
final class PublicUrl
{
    /** @var array<string, array<int, array{sef:string, vars:array<string, string>, main:bool}>>|null */
    private static ?array $rows = null;

    /**
     * The public address of a content item.
     *
     * @param string $view      `article`, `category`, ...
     * @param string $built     The address Joomla's router produced, used when nothing better is known.
     */
    public static function forContent(string $view, int $id, string $language, string $built, string $option = 'com_content'): string
    {
        $path = self::pick(self::load(), $option, $view, $id, $language);

        return $path === null ? $built : self::absolute($path);
    }

    /**
     * The id of the article published at a public path, or null.
     *
     * Duplicates are included on purpose: this is how an old or technical
     * address is mapped back to its page.
     */
    public static function idForPath(string $path, string $option = 'com_content', string $view = 'article'): ?int
    {
        return self::reverse(self::load(), $path, $option, $view);
    }

    /** Forgets the cached table, for tests and long-running jobs. */
    public static function reset(): void
    {
        self::$rows = null;
    }

    /**
     * The best row for an item: same component, view and id; the main address
     * rather than a duplicate; the item's own language when the table records one.
     *
     * @param array<string, array<int, array{sef:string, vars:array<string, string>, main:bool}>> $index
     */
    public static function pick(array $index, string $option, string $view, int $id, string $language = ''): ?string
    {
        $best = null;
        $bestScore = -1;
        $short = strtolower(explode('-', $language)[0]);

        foreach ($index[$option . '|' . $view . '|' . $id] ?? [] as $row) {
            if (!$row['main'] || $row['sef'] === '') {
                continue;
            }

            $score = 1;
            $lang = strtolower(explode('-', (string) ($row['vars']['lang'] ?? ''))[0]);

            if ($lang !== '' && $short !== '' && $short !== '*') {
                // A row recorded for another language is not this item's address.
                if ($lang !== $short) {
                    continue;
                }
                $score = 2;
            }

            if ($score > $bestScore) {
                $best = $row['sef'];
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @param array<string, array<int, array{sef:string, vars:array<string, string>, main:bool}>> $index
     */
    public static function reverse(array $index, string $path, string $option = 'com_content', string $view = 'article'): ?int
    {
        $wanted = self::key($path);

        if ($wanted === '') {
            return null;
        }

        foreach ($index as $key => $rows) {
            [$rowOption, $rowView, $id] = explode('|', $key, 3);

            if ($rowOption !== $option || $rowView !== $view) {
                continue;
            }

            foreach ($rows as $row) {
                if (self::key($row['sef']) === $wanted) {
                    return (int) $id;
                }
            }
        }

        return null;
    }

    /**
     * Turns the rows of a SEF table into a lookup by component, view and id.
     *
     * @param array<int, array{sef:string, nonsef:string, main:bool}> $records
     * @return array<string, array<int, array{sef:string, vars:array<string, string>, main:bool}>>
     */
    public static function index(array $records): array
    {
        $index = [];

        foreach ($records as $record) {
            $sef = trim((string) $record['sef']);
            $nonsef = html_entity_decode((string) $record['nonsef'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $query = (string) (parse_url($nonsef, PHP_URL_QUERY) ?? (str_contains($nonsef, '?') ? substr($nonsef, (int) strpos($nonsef, '?') + 1) : $nonsef));
            parse_str($query, $vars);

            $option = (string) ($vars['option'] ?? '');
            $view = (string) ($vars['view'] ?? '');
            $id = $vars['id'] ?? '';

            if (is_array($id)) {
                $id = $id[0] ?? '';
            }

            // "1226", "1226:alias" and "1226-alias" all mean article 1226.
            $id = preg_replace('/[-:].*$/', '', (string) $id);

            if ($sef === '' || $option === '' || $view === '' || $id === '' || !ctype_digit((string) $id)) {
                continue;
            }

            $index[$option . '|' . $view . '|' . (int) $id][] = [
                'sef' => $sef,
                'vars' => array_map('strval', array_filter($vars, 'is_scalar')),
                'main' => (bool) $record['main'],
            ];
        }

        return $index;
    }

    /** Comparable form of a path: no domain, no slashes at the edges, no suffix, lower case. */
    private static function key(string $path): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?? '');
        }

        $path = rawurldecode(explode('?', explode('#', $path, 2)[0], 2)[0]);
        $path = (string) preg_replace('#\.html?$#i', '', trim($path, '/'));

        return mb_strtolower($path);
    }

    private static function absolute(string $sef): string
    {
        if (preg_match('#^https?://#i', $sef)) {
            return $sef;
        }

        return rtrim(Uri::root(), '/') . '/' . ltrim($sef, '/');
    }

    /**
     * @return array<string, array<int, array{sef:string, vars:array<string, string>, main:bool}>>
     */
    private static function load(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        self::$rows = [];

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $tables = array_map('strtolower', $db->getTableList());
            $prefix = strtolower($db->getPrefix());
            $records = [];

            if (in_array($prefix . 'forsef_urls', $tables, true)) {
                $query = $db->getQuery(true)
                    ->select([$db->quoteName('sef'), $db->quoteName('nonsef'), $db->quoteName('duplicate')])
                    ->from($db->quoteName('#__forsef_urls'))
                    ->where($db->quoteName('nonsef') . ' LIKE ' . $db->quote('%option=com_content%'));

                foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
                    $records[] = ['sef' => (string) $row['sef'], 'nonsef' => (string) $row['nonsef'], 'main' => (int) $row['duplicate'] === 0];
                }
            }

            if (in_array($prefix . 'sh404sef_urls', $tables, true)) {
                $query = $db->getQuery(true)
                    ->select([$db->quoteName('oldurl'), $db->quoteName('newurl'), $db->quoteName('rank')])
                    ->from($db->quoteName('#__sh404sef_urls'))
                    ->where($db->quoteName('newurl') . ' LIKE ' . $db->quote('%option=com_content%'));

                foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
                    $records[] = ['sef' => (string) $row['oldurl'], 'nonsef' => (string) $row['newurl'], 'main' => (int) $row['rank'] === 0];
                }
            }

            self::$rows = self::index($records);
        } catch (\Throwable) {
            // A table that is not what this class expects must not stop llms.txt:
            // the address Joomla built is used instead.
            self::$rows = [];
        }

        return self::$rows;
    }
}
