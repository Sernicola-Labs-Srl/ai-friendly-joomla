<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Sernicola\Library\AiFriendly\Rule\RuleEngine;
use Sernicola\Library\AiFriendly\Support\EventNames;
use Sernicola\Library\AiFriendly\Support\Path;
use Sernicola\Library\AiFriendly\Support\Lang;

final class LlmsBuilder
{
    public function __construct(private readonly ContentRegistry $registry, private readonly RuleEngine $rules, private readonly array $options)
    {
    }

    public function build(): string
    {
        $custom = trim((string) ($this->options['llms_content'] ?? ''));
        $auto = !empty($this->options['llms_include_auto']);
        if ($custom !== '' && !$auto) {
            return $this->dispatch(html_entity_decode($custom, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $config = Factory::getApplication()->getConfig();
        $output = $custom !== '' ? $custom . "\n\n" : '# ' . $config->get('sitename', 'Joomla') . "\n> " . ($config->get('MetaDesc') ?: Lang::text('PLG_SYSTEM_AIFRIENDLY_SITE_FALLBACK_DESC', 'Website')) . "\n\n";
        if ($auto || $custom === '') {
            $sections = [];
            // Two articles can end up at one public address (a duplicate, an
            // alias): listing it twice only adds a link that redirects.
            $seen = [];
            $limit = max(1, (int) ($this->options['articles_limit'] ?? 100));
            foreach ($this->registry->all([], $limit) as $item) {
                if (!$this->rules->allows($item, 'llms')) {
                    continue;
                }
                $address = Path::markdownUrl($item->canonicalUrl);
                // Keyed by the full public URL: on a site without friendly URLs the
                // query string is what tells the pages apart.
                $identity = Path::publicUrl($item->canonicalUrl);
                if (isset($seen[$identity])) {
                    continue;
                }
                $seen[$identity] = true;
                $heading = $item->type === 'article'
                    ? Lang::text('PLG_SYSTEM_AIFRIENDLY_LLMS_ARTICLES', 'Articles')
                    : Lang::text('PLG_SYSTEM_AIFRIENDLY_LLMS_PAGES', 'Pages');
                $sections[$heading][] = '- [' . $item->title . '](' . $address . ')' . ($item->excerpt !== '' ? ': ' . $item->excerpt : '');
            }
            foreach ($sections as $heading => $lines) {
                $output .= '## ' . $heading . "\n" . implode("\n", $lines) . "\n\n";
            }
        }

        return $this->dispatch(html_entity_decode(trim($output) . "\n", ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function dispatch(string $content): string
    {
        $event = new Event(EventNames::BUILD_LLMS, ['content' => $content, 'site' => Uri::root()]);
        Factory::getApplication()->getDispatcher()->dispatch(EventNames::BUILD_LLMS, $event);

        return (string) $event->getArgument('content', $content);
    }
}

