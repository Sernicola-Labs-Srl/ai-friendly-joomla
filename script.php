<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
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
        $this->announceFourSeo();
    }

    /**
     * Takes the optional 4SEO bridge out of the 4SEO functions file. It only touches
     * lines that AI Friendly added (the fenced block, or the single include line that
     * earlier versions asked users to paste), and deletes the file only when AI Friendly
     * created it and nothing else is left in it. The markers are the same as in
     * Sernicola\Library\AiFriendly\Integration\ForSeo.
     */
    public function uninstall($parent): bool
    {
        $file = JPATH_LIBRARIES . '/weeblr/forseo_functions.php';

        if (!is_file($file) || !is_writable($file)) {
            return true;
        }

        $content = (string) file_get_contents($file);
        $cleaned = (string) preg_replace('#\R?// BEGIN AI Friendly bridge.*?// END AI Friendly bridge\R?#s', '', $content);
        $cleaned = (string) preg_replace('#^[ \t]*include[ \t]+JPATH_LIBRARIES[ \t]*\.[ \t]*.{1}/aifriendly/forseo-bridge\.php.{1}[ \t]*;[ \t]*\R?#m', '', $cleaned);

        if ($cleaned === $content) {
            return true;
        }

        $code = (string) preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $cleaned);
        $code = (string) preg_replace('#<\?php|\?>|defined\(\s*.{1}_JEXEC.{1}\s*\)\s*or\s+die\s*;#i', '', $code);

        if (str_contains($content, '// File created by AI Friendly') && trim($code) === '') {
            @unlink($file);
        } else {
            file_put_contents($file, $cleaned, LOCK_EX);
        }

        return true;
    }

    /** Tells the administrator that the 4SEO bridge exists; nothing is changed here. */
    private function announceFourSeo(): void
    {
        $file = JPATH_LIBRARIES . '/weeblr/forseo_functions.php';

        if (!is_dir(JPATH_LIBRARIES . '/weeblr')) {
            return;
        }

        if (is_file($file) && str_contains((string) file_get_contents($file), 'aifriendly/forseo-bridge.php')) {
            return;
        }

        $message = Text::_('PKG_AIFRIENDLY_FORSEO_NOTICE');

        if ($message === 'PKG_AIFRIENDLY_FORSEO_NOTICE') {
            $message = '4SEO was detected. AI Friendly can add a few lines to the 4SEO functions file so that its structured data includes your site identity. Nothing has been changed: enable it, if you want it, from Components > AI Friendly > Schema. The lines are removed again if you uninstall AI Friendly.';
        }

        Factory::getApplication()->enqueueMessage($message, 'info');
    }
}
