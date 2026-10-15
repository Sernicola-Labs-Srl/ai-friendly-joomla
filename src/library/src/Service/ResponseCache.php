<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Sernicola\Library\AiFriendly\Support\Options;

/**
 * Caches generated Markdown so a hit does not repeat the database queries, the
 * content plugins and the HTML conversion behind it.
 *
 * Disabled by default: serving a stale public document is a worse failure than
 * doing the work twice, so it takes an explicit TTL to switch on.
 *
 * Entries are content-addressed. The key folds in everything that changes the
 * output, so an edit or a settings change lands on a different key rather than
 * needing invalidation, and the superseded entry simply expires.
 */
final class ResponseCache
{
    private const GROUP = 'aifriendly';

    /** Options that change generated Markdown. */
    private const RELEVANT_OPTIONS = [
        'include_metadata',
        'normalize_headings',
        'render_content_plugins',
        'link_to_markdown',
        'llms_content',
        'llms_include_auto',
        'articles_limit',
        'include_articles',
        'include_categories',
        'include_contacts',
        'include_tags',
        'exclude_categories',
        'exclude_tags',
        'exclude_url_patterns',
        'exclude_noindex',
    ];

    private $controller = null;

    public function __construct(private readonly int $ttl)
    {
    }

    public static function ttl(array $options): int
    {
        return max(0, (int) ($options['dynamic_cache_ttl'] ?? 0));
    }

    public function enabled(): bool
    {
        return $this->ttl > 0;
    }

    /**
     * Returns the cached value for $id, producing and storing it on a miss.
     *
     * A cache fault never costs the visitor the page: the producer runs anyway.
     */
    public function remember(string $id, callable $producer): string
    {
        if (!$this->enabled()) {
            return (string) $producer();
        }

        try {
            $controller = $this->controller();
            $cached = $controller->get($id);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $value = (string) $producer();
            $controller->store($value, $id);

            return $value;
        } catch (\Throwable) {
            return (string) $producer();
        }
    }

    /**
     * Builds a cache id from the parts given plus everything ambient that
     * changes the output.
     *
     * The caller's view levels are part of it on purpose. Access is evaluated
     * against the current identity, so a document generated for a privileged
     * user must never be handed back to a guest from the cache.
     */
    public function id(string ...$parts): string
    {
        return hash('sha256', implode('|', [...$parts, $this->ambient()]));
    }

    private function ambient(): string
    {
        $app = Factory::getApplication();
        $options = Options::get();

        $relevant = [];
        foreach (self::RELEVANT_OPTIONS as $key) {
            $value = $options[$key] ?? '';
            $relevant[] = $key . '=' . (is_array($value) ? implode(',', $value) : (string) $value);
        }

        try {
            $levels = implode(',', (array) $app->getIdentity()->getAuthorisedViewLevels());
        } catch (\Throwable) {
            $levels = 'guest';
        }

        try {
            $language = $app->getLanguage()->getTag();
        } catch (\Throwable) {
            $language = '*';
        }

        return implode('|', [$language, $levels, implode(';', $relevant)]);
    }

    private function controller()
    {
        if ($this->controller !== null) {
            return $this->controller;
        }

        return $this->controller = Factory::getContainer()
            ->get(CacheControllerFactoryInterface::class)
            ->createCacheController('output', [
                'defaultgroup' => self::GROUP,
                // Joomla expects minutes here and multiplies by 60 internally.
                'lifetime' => max(1, (int) ceil($this->ttl / 60)),
                // Our own TTL is the explicit opt-in, independent of whether the
                // site enables global page caching.
                'caching' => true,
            ]);
    }
}
