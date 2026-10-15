<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Dto;

defined('_JEXEC') or die;

final class ContentItem
{
    public function __construct(
        public readonly string $provider,
        public readonly string $type,
        public readonly string $id,
        public readonly string $language,
        public readonly string $title,
        public readonly string $canonicalUrl,
        public readonly string $routePath,
        public readonly string $body,
        public readonly string $excerpt = '',
        public readonly array $metadata = [],
        public readonly array $taxonomies = [],
        public readonly string $modified = '',
        public readonly bool $published = true,
        public readonly bool $accessible = true,
    ) {
    }

    public function key(): string
    {
        return implode(':', [$this->provider, $this->type, $this->id, $this->language ?: '*']);
    }

    public function staticFilename(): string
    {
        $parts = array_map(
            static fn (string $part): string => trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', $part), '-'),
            [$this->provider, $this->type, $this->id, $this->language ?: 'all']
        );

        return strtolower(implode('-', $parts)) . '.md';
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'provider' => $this->provider,
            'type' => $this->type,
            'id' => $this->id,
            'language' => $this->language,
            'title' => $this->title,
            'canonical_url' => $this->canonicalUrl,
            'route_path' => $this->routePath,
            'excerpt' => $this->excerpt,
            'metadata' => $this->metadata,
            'taxonomies' => $this->taxonomies,
            'modified' => $this->modified,
            'published' => $this->published,
            'accessible' => $this->accessible,
        ];
    }
}

