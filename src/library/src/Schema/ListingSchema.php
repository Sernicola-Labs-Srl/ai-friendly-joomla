<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Schema;

defined('_JEXEC') or die;

use Sernicola\Library\AiFriendly\Integration\CoreSchema;

/**
 * The graph of a listing page: a category, the archive, the featured articles.
 *
 * The Joomla Schema.org plugin only publishes a graph for the contexts that
 * ship a schema form, and those are single articles. Listing pages are left
 * with the breadcrumb of whoever provides one. This builds for them the same
 * organization and website nodes the articles carry, with the same ids, plus a
 * CollectionPage that lists what the page shows.
 */
final class ListingSchema
{
    /** How many entries of the list are published; the page itself paginates. */
    public const LIMIT = 50;

    /**
     * @param array<int, mixed> $ours Nodes from SchemaBuilder, without a page item.
     * @param array{url:string, title:string, description:string, language:string, site:string} $page
     * @param array<int, array{url:string, name:string}> $items
     * @return array<int, mixed>
     */
    public static function graph(array $ours, string $home, array $page, array $items): array
    {
        $orgId = $home . '#/schema/Organization/base';
        $websiteId = $home . '#/schema/WebSite/base';

        // The core nodes as Joomla would have published them on an article.
        $graph = [
            ['@type' => 'Organization', '@id' => $orgId, 'name' => $page['site'], 'url' => $home],
            ['@type' => 'WebSite', '@id' => $websiteId, 'url' => $home, 'name' => $page['site'], 'publisher' => ['@id' => $orgId]],
        ];

        $graph = CoreSchema::merge($graph, $ours, $home);

        $list = [];
        $position = 0;

        foreach (array_slice($items, 0, self::LIMIT) as $item) {
            if (($item['url'] ?? '') === '' || ($item['name'] ?? '') === '') {
                continue;
            }

            $list[] = ['@type' => 'ListItem', 'position' => ++$position, 'url' => $item['url'], 'name' => $item['name']];
        }

        $node = [
            '@type' => 'CollectionPage',
            '@id' => $page['url'] . '#collection',
            'url' => $page['url'],
            'name' => $page['title'],
            'description' => $page['description'],
            'inLanguage' => $page['language'],
            'isPartOf' => ['@id' => $websiteId],
            'about' => ['@id' => $orgId],
            'publisher' => ['@id' => $orgId],
        ];

        if ($list) {
            $node['mainEntity'] = ['@type' => 'ItemList', 'numberOfItems' => count($list), 'itemListElement' => $list];
        }

        $graph[] = array_filter($node, static fn ($value): bool => $value !== '' && $value !== []);

        return array_values($graph);
    }
}
