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
 * The meta description an editor wrote in 4SEO for a page, when 4SEO is installed.
 *
 * 4SEO keeps per-page overrides in `#__forseo_custom_meta`: one row per page,
 * indexed by `url`, with a JSON `data` column whose `custom.description` key is
 * the description. The table is optional; its absence, or a layout that is not
 * the one described here, simply yields no description.
 */
final class SeoMeta
{
    /** @var array<string, bool> */
    private static array $present = [];

    /** @var array<string, string> */
    private static array $cache = [];

    /**
     * @param string $url Public address of the page, absolute.
     */
    public static function description(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (array_key_exists($url, self::$cache)) {
            return self::$cache[$url];
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $table = strtolower($db->getPrefix()) . 'forseo_custom_meta';

            self::$present[$table] ??= in_array($table, array_map('strtolower', $db->getTableList()), true);

            if (!self::$present[$table]) {
                return self::$cache[$url] = '';
            }

            $candidates = array_map('strval', self::candidates($url, (string) Uri::base(true)));
            $query = $db->getQuery(true)
                ->select($db->quoteName('data'))
                ->from($db->quoteName('#__forseo_custom_meta'))
                ->where($db->quoteName('url') . ' IN (' . implode(',', array_map([$db, 'quote'], $candidates)) . ')');

            foreach ($db->setQuery($query)->loadColumn() ?: [] as $data) {
                if (($description = self::fromData((string) $data)) !== '') {
                    return self::$cache[$url] = $description;
                }
            }
        } catch (\Throwable) {
            // Not 4SEO's layout: no description from it.
        }

        return self::$cache[$url] = '';
    }

    /** `custom.description` of a `data` document, or an empty string. */
    public static function fromData(string $json): string
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return '';
        }

        $value = $data['custom']['description'] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * The spellings under which 4SEO may have indexed the page: absolute,
     * path only, with and without the leading and trailing slash.
     *
     * @return string[]
     */
    public static function candidates(string $url, string $basePath = ""): array
    {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '');
        $base = $basePath === "" ? "/" : Path::normalize($basePath);

        $list = [$url, rtrim($url, '/'), $path, ltrim($path, '/'), rtrim($path, '/')];

        if ($base !== '/' && str_starts_with($path, $base)) {
            $relative = substr($path, strlen($base));
            $list[] = $relative;
            $list[] = ltrim($relative, '/');
        }

        return array_values(array_unique(array_filter($list, static fn (string $v): bool => $v !== '')));
    }
}
