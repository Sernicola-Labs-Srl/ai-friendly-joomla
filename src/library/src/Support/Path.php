<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Support;

defined('_JEXEC') or die;

final class Path
{
    public static function normalize(string $path): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?? '/');
        } else {
            $path = explode('?', explode('#', $path, 2)[0], 2)[0];
        }
        $path = rawurldecode($path);
        $path = preg_replace('#/+#', '/', str_replace('\\', '/', $path)) ?? $path;
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public static function withoutMarkdownSuffix(string $path): string
    {
        $path = self::normalize($path);
        if (in_array(strtolower($path), ['/.md', '/index.html.md'], true)) {
            return '/';
        }

        $path = preg_replace('#(?:/index\.html\.md|\.md)$#i', '', $path) ?? $path;

        return self::normalize($path);
    }

    /**
     * Drops the `.md` suffix and nothing else.
     *
     * withoutMarkdownSuffix() normalises, and normalising decodes
     * percent-encoding. The request URI has to keep its own encoding, because
     * the router decodes it itself and would otherwise decode twice.
     */
    public static function stripMarkdownSuffix(string $path): string
    {
        $stripped = preg_replace('#(?:/index\.html\.md|\.md)$#i', '', $path);

        return ($stripped === null || $stripped === '') ? '/' : $stripped;
    }

    /**
     * Drops the SEF suffix Joomla appends to routes, typically `.html`.
     *
     * Safe to apply to an alias: Joomla strips dots when sanitising one, so a
     * trailing extension is always the router's doing and never part of it.
     */
    public static function withoutSefSuffix(string $path): string
    {
        return (string) preg_replace('#\.html?$#i', '', self::normalize($path));
    }

    /**
     * The content alias a request path points at, with the numeric prefix and
     * the SEF suffix removed.
     */
    public static function alias(string $path): string
    {
        $alias = basename(self::normalize($path));
        $alias = (string) preg_replace('/^\d+-/', '', $alias);

        return (string) preg_replace('#\.html?$#i', '', $alias);
    }

    /**
     * The address to publish for a page: Joomla's route for an item carries
     * `lang=*` (or the language tag), which is a routing hint and not part of
     * the address a visitor uses. Other query parameters are kept, because on a
     * site without friendly URLs they identify the page.
     */
    public static function publicUrl(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $hash = strpos($url, '#');
        $fragment = $hash === false ? '' : substr($url, $hash);
        $base = $hash === false ? $url : substr($url, 0, $hash);
        $mark = strpos($base, '?');
        if ($mark === false) {
            return $url;
        }

        parse_str(substr($base, $mark + 1), $query);
        unset($query['lang']);
        $base = substr($base, 0, $mark);

        return $base . ($query ? '?' . http_build_query($query) : '') . $fragment;
    }

    public static function markdownUrl(string $canonical): string
    {
        $parts = parse_url($canonical);
        $path = self::normalize((string) ($parts['path'] ?? '/'));
        $path = $path === '/' ? '/index.html.md' : $path . '.md';
        $origin = isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : '';

        return $origin . $path;
    }

    /**
     * Where a `.md` request for $requested should be sent, or null to serve it.
     *
     * A page has one public address. A request that reaches it by another path
     * (the technical `/section/1226-alias.html`, a different case, a language
     * prefix left out) is answered with a permanent redirect to the Markdown of
     * the public one. The SEF suffix alone is not a difference: `/page` and
     * `/page.html` have always reached the same document.
     *
     * Nothing is redirected when the canonical address carries a query string
     * (a site without friendly URLs: the path alone does not identify the page)
     * or when either path is the home page.
     *
     * @param string $requested Path asked for, without the `.md` suffix and the site's base path.
     * @param string $basePath  Folder the site lives in, `/` for the domain root.
     */
    public static function markdownRedirect(string $requested, string $canonicalUrl, string $basePath = '/'): ?string
    {
        $canonical = self::publicUrl($canonicalUrl);

        if (str_contains($canonical, '?')) {
            return null;
        }

        $path = self::normalize((string) (parse_url($canonical, PHP_URL_PATH) ?? '/'));
        $base = self::normalize($basePath);

        if ($base !== '/' && str_starts_with($path, $base)) {
            $path = self::normalize(substr($path, strlen($base)) ?: '/');
        }

        $requested = self::normalize($requested);

        if ($path === '/' || $requested === '/' || self::withoutSefSuffix($path) === self::withoutSefSuffix($requested)) {
            return null;
        }

        return self::markdownUrl($canonical);
    }
}
