<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Markdown;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Sernicola\Library\AiFriendly\Dto\ContentItem;
use Sernicola\Library\AiFriendly\Support\EventNames;
use Sernicola\Library\AiFriendly\Support\Lang;

final class Generator
{
    public function __construct(private readonly array $options)
    {
    }

    public function generate(ContentItem $item): string
    {
        $converter = new Converter(
            !empty($this->options['normalize_headings']),
            rtrim(Uri::root(), '/') . '/',
            !empty($this->options['link_to_markdown'])
        );
        $content = $converter->convert($item->body);
        if ($content === '') {
            $content = $converter->convert($item->excerpt);
        }

        $markdown = !empty($this->options['include_metadata']) ? $this->frontmatter($item) : '';
        $markdown .= '# ' . $item->title . "\n\n";
        $image = (string) ($item->metadata['featured_image'] ?? '');
        if ($image !== '') {
            $markdown .= '![' . $item->title . '](' . $image . ")\n\n";
        }
        $markdown .= ($content !== '' ? $content : Lang::text('PLG_SYSTEM_AIFRIENDLY_CONTENT_UNAVAILABLE', 'Content is not available.')) . "\n";

        $event = new Event(EventNames::PREPARE_MARKDOWN, ['item' => $item, 'markdown' => $markdown]);
        Factory::getApplication()->getDispatcher()->dispatch(EventNames::PREPARE_MARKDOWN, $event);

        return trim((string) $event->getArgument('markdown', $markdown)) . "\n";
    }

    private function frontmatter(ContentItem $item): string
    {
        $meta = array_merge($item->metadata, [
            'title' => $item->title,
            'url' => $item->canonicalUrl,
            'language' => $item->language,
            'modified' => $item->modified,
        ]);
        $ordered = ['title', 'description', 'featured_image', 'date', 'modified', 'author', 'url', 'language', 'category', 'tags'];
        $yaml = "---\n";
        foreach ($ordered as $key) {
            $value = $meta[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $yaml .= $key . ': [' . implode(', ', array_map([$this, 'yamlEscape'], $value)) . "]\n";
            } else {
                $yaml .= $key . ': ' . $this->yamlEscape((string) $value) . "\n";
            }
        }

        return $yaml . "---\n\n";
    }

    public function yamlEscape(string $value): string
    {
        if ($value === '' || preg_match('/[:\[\]{}#&*!|>\'"%@`\r\n]/u', $value)) {
            return '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ' '], $value) . '"';
        }

        return $value;
    }
}

