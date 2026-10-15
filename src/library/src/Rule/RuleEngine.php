<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Rule;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Event\Event;
use Sernicola\Library\AiFriendly\Contract\RuleInterface;
use Sernicola\Library\AiFriendly\Dto\ContentItem;
use Sernicola\Library\AiFriendly\Storage\Repository;
use Sernicola\Library\AiFriendly\Support\EventNames;

final class RuleEngine
{
    /** @param RuleInterface[] $rules */
    public function __construct(private readonly array $options, private readonly Repository $repository, private readonly array $rules = [])
    {
    }

    public function allows(ContentItem $item, string $context = 'public'): bool
    {
        $allowed = $item->published && $item->accessible && !$this->repository->isExcluded($item->key());
        if ($allowed && !$this->allowsType($item)) {
            $allowed = false;
        }
        $categories = array_map('intval', (array) ($this->options['exclude_categories'] ?? []));
        $tags = array_map('intval', (array) ($this->options['exclude_tags'] ?? []));
        if ($allowed && array_intersect($categories, array_map('intval', (array) ($item->taxonomies['category_ids'] ?? [])))) {
            $allowed = false;
        }
        if ($allowed && array_intersect($tags, array_map('intval', (array) ($item->taxonomies['tag_ids'] ?? [])))) {
            $allowed = false;
        }
        if ($allowed && !empty($this->options['exclude_noindex']) && !empty($item->metadata['noindex'])) {
            $allowed = false;
        }
        if ($allowed && $this->matchesUrlPattern($item->canonicalUrl)) {
            $allowed = false;
        }
        foreach ($this->rules as $rule) {
            if (!$allowed) {
                break;
            }

            try {
                $allowed = (bool) $rule->allows($item, $this->options);
            } catch (\Throwable $error) {
                // Fail open. A third-party rule that crashes must not quietly
                // pull the site's content out of llms.txt; the fault is
                // announced instead, so it can be found and fixed.
                $this->report('rule ' . $rule::class . ' failed on ' . $item->key() . ': ' . $error->getMessage());
            }
        }

        $event = new Event(EventNames::CAN_EXPOSE, ['allowed' => $allowed, 'item' => $item, 'context' => $context]);
        Factory::getApplication()->getDispatcher()->dispatch(EventNames::CAN_EXPOSE, $event);

        return (bool) $event->getArgument('allowed', $allowed);
    }

    private function report(string $message): void
    {
        try {
            $event = new Event(EventNames::RUNTIME_ERROR, ['message' => $message, 'context' => 'rules']);
            Factory::getApplication()->getDispatcher()->dispatch(EventNames::RUNTIME_ERROR, $event);
        } catch (\Throwable) {
            // Reporting a problem must not become the problem.
        }
    }

    /** Content type to the scope toggle that governs it. */
    private const TYPE_OPTIONS = [
        'article' => 'include_articles',
        'contact' => 'include_contacts',
        'tag' => 'include_tags',
    ];

    /**
     * Honours the scope toggles of the Rules section. Types contributed by
     * third-party providers are not gated here: they have no toggle of their
     * own, and silently hiding them would be worse than showing them.
     */
    private function allowsType(ContentItem $item): bool
    {
        $option = self::TYPE_OPTIONS[$item->type] ?? null;

        return $option === null || !empty($this->options[$option]);
    }

    private function matchesUrlPattern(string $url): bool
    {
        $patterns = preg_split('/\R/u', (string) ($this->options['exclude_url_patterns'] ?? '')) ?: [];
        foreach (array_filter(array_map('trim', $patterns)) as $pattern) {
            if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/') && @preg_match($pattern, $url)) {
                return true;
            }
            $quoted = str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '#'));
            if (@preg_match('#' . $quoted . '#i', $url)) {
                return true;
            }
        }

        return false;
    }
}

