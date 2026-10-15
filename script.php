<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Filesystem\Folder;

final class Pkg_AifriendlyInstallerScript
{
    public function preflight(string $type, $parent): bool
    {
        if (!in_array($type, ['install', 'update', 'discover_install'], true)) {
            return true;
        }

        // Supported lines: Joomla 5.4 up to (not including) 6.0, and 6.1 or later.
        $supported = (version_compare(JVERSION, '5.4', '>=') && version_compare(JVERSION, '6.0', '<'))
            || version_compare(JVERSION, '6.1', '>=');
        if (!$supported) {
            Factory::getApplication()->enqueueMessage(
                sprintf('AI Friendly requires Joomla 5.4, or Joomla 6.1 and later; this site runs Joomla %s.', JVERSION),
                'error'
            );

            return false;
        }

        return true;
    }

    public function postflight(string $type, $parent): void
    {
        if (!in_array($type, ['install', 'update'], true)) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('aifriendly'));
        $legacy = $db->setQuery($query)->loadObject();

        $query = $db->getQuery(true)
            ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_aifriendly'));
        $component = $db->setQuery($query)->loadObject();

        if ($legacy && $component) {
            $old = json_decode((string) $legacy->params, true) ?: [];
            $current = json_decode((string) $component->params, true) ?: [];
            $migrated = array_merge($old, $current);
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($migrated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
                ->where($db->quoteName('extension_id') . ' = ' . (int) $component->extension_id);
            $db->setQuery($query)->execute();
        }

        foreach ([['system', 'aifriendly'], ['content', 'aifriendly'], ['task', 'aifriendly']] as [$folder, $element]) {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote($folder))
                ->where($db->quoteName('element') . ' = ' . $db->quote($element));
            $db->setQuery($query)->execute();
        }

        Folder::create(JPATH_ROOT . '/media/com_aifriendly/versions');
    }
}
