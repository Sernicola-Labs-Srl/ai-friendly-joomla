<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Integration;

defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;
use Sernicola\Library\AiFriendly\Dto\ContentItem;
use Sernicola\Library\AiFriendly\Schema\ListingSchema;
use Sernicola\Library\AiFriendly\Schema\OutputMode;
use Sernicola\Library\AiFriendly\Schema\PageSchema;
use Sernicola\Library\AiFriendly\Schema\SchemaBuilder;
use Sernicola\Library\AiFriendly\Support\Options;

/**
 * Merges the AI Friendly identity into the graph the Joomla Schema.org plugin
 * publishes on every page.
 *
 * The core plugin already builds an Organization, a WebSite and a WebPage with
 * stable ids (`#/schema/Organization/base` and so on) and links every article
 * to them. Rather than publishing a second organization next to it, the
 * identity is laid over those very nodes: one entity per site, the same id on
 * every page, and every reference in the graph keeps pointing at it.
 */
final class CoreSchema
{
    private const ORGANIZATION_SUFFIX = '#/schema/Organization/base';
    private const WEBSITE_SUFFIX = '#/schema/WebSite/base';

    /**
     * Event handler entry point: returns the graph unchanged when inactive.
     *
     * @param callable():array<int, int>|null $categoryIds Category of the page followed by its ancestors.
     * @param callable():array{introtext:string, fulltext:string}|null $raw Stored article text.
     */
    public static function graph(array $graph, ?ContentItem $item = null, ?callable $categoryIds = null, ?callable $raw = null): array
    {
        $options = Options::get();

        if (empty($options['schema_enabled']) || OutputMode::resolve($options) !== OutputMode::CORE) {
            return $graph;
        }

        $home = rtrim(Uri::root(), '/') . '/';
        $graph = self::merge($graph, (new SchemaBuilder($options))->graph(), $home);

        if ($item === null || $categoryIds === null || $raw === null || trim((string) ($options['schema_page_rules'] ?? '')) === '') {
            return $graph;
        }

        $rule = PageSchema::match(PageSchema::rules((string) $options['schema_page_rules']), (int) $item->id, $categoryIds());

        return $rule === null ? $graph : PageSchema::apply($graph, $item, $rule, $home, $raw);
    }

    /**
     * The graph of a listing page, or null when the integration is inactive.
     *
     * @param array{url:string, title:string, description:string, language:string, site:string} $page
     * @param array<int, array{url:string, name:string}> $items
     * @return array<int, mixed>|null
     */
    public static function listing(array $page, array $items): ?array
    {
        $options = Options::get();

        if (empty($options['schema_enabled']) || OutputMode::resolve($options) !== OutputMode::CORE) {
            return null;
        }

        $home = rtrim(Uri::root(), '/') . '/';

        return ListingSchema::graph((new SchemaBuilder($options))->graph(), $home, $page, $items);
    }

    /**
     * @param array<int, mixed> $graph The nodes published by Joomla.
     * @param array<int, mixed> $ours  The nodes built by SchemaBuilder, no page item.
     * @return array<int, mixed>
     */
    public static function merge(array $graph, array $ours, string $home): array
    {
        $orgKey = self::find($graph, self::ORGANIZATION_SUFFIX);

        // Without the core organization there is nothing to extend, and this
        // class does not author a graph of its own.
        if ($orgKey === null) {
            return $graph;
        }

        $orgId = (string) $graph[$orgKey]['@id'];
        $websiteKey = self::find($graph, self::WEBSITE_SUFFIX);
        $websiteId = $websiteKey !== null ? (string) $graph[$websiteKey]['@id'] : null;

        $map = [$home . '#identity' => $orgId];

        if ($websiteId !== null) {
            $map[$home . '#website'] = $websiteId;
        }

        foreach ($ours as $node) {
            if (!is_array($node)) {
                continue;
            }

            $node = self::remap($node, $map);
            $id = (string) ($node['@id'] ?? '');

            if ($id === '') {
                continue;
            }

            $key = self::indexOf($graph, $id);

            if ($key !== null) {
                $graph[$key] = self::overlay($graph[$key], $node);
            } elseif ($id !== $home . '#website') {
                // A WebSite of ours that found no core WebSite is dropped
                // rather than published as a second one.
                $graph[] = $node;
            }
        }

        return array_values($graph);
    }

    private static function find(array $graph, string $suffix): ?int
    {
        foreach ($graph as $key => $node) {
            $id = is_array($node) ? (string) ($node['@id'] ?? '') : '';

            if ($id !== '' && str_ends_with($id, $suffix)) {
                return $key;
            }
        }

        return null;
    }

    private static function indexOf(array $graph, string $id): ?int
    {
        foreach ($graph as $key => $node) {
            if (is_array($node) && ($node['@id'] ?? null) === $id) {
                return $key;
            }
        }

        return null;
    }

    /** Rewrites every `@id` that names one of our provisional ids, wherever it appears. */
    private static function remap(array $value, array $map): array
    {
        foreach ($value as $key => $item) {
            if ($key === '@id' && is_string($item) && isset($map[$item])) {
                $value[$key] = $map[$item];
            } elseif (is_array($item)) {
                $value[$key] = self::remap($item, $map);
            }
        }

        return $value;
    }

    /**
     * Our values win over Joomla's on the same property; properties only Joomla
     * set are kept. Associative arrays merge key by key, lists are replaced.
     */
    private static function overlay(array $base, array $ours): array
    {
        foreach ($ours as $key => $value) {
            if ($key === '@id') {
                continue;
            }

            if (is_array($value) && !array_is_list($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key]) && !isset($value['@id'])) {
                $base[$key] = self::overlay($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
