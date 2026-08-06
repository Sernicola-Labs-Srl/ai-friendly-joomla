<?php

namespace Sernicola\Library\AiFriendly\Support;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * Translation helper for strings the library emits.
 *
 * The library is shared by the component, the system plugin and the task plugin,
 * so it cannot assume which language files the current request has already loaded.
 * Every call therefore carries an English fallback: if the key is missing the
 * output is still a readable sentence rather than a raw language key.
 */
final class Lang
{
    private static bool $loaded = false;

    public static function text(string $key, string $fallback): string
    {
        self::ensureLoaded();

        $value = Text::_($key);

        return $value === $key ? $fallback : $value;
    }

    public static function sprintf(string $key, string $fallback, ...$args): string
    {
        return vsprintf(self::text($key, $fallback), $args);
    }

    private static function ensureLoaded(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        try {
            $language = Factory::getApplication()->getLanguage();
            $language->load('com_aifriendly', JPATH_ADMINISTRATOR);
            $language->load('plg_system_aifriendly', JPATH_ADMINISTRATOR)
                || $language->load('plg_system_aifriendly', JPATH_PLUGINS . '/system/aifriendly');
        } catch (\Throwable) {
            // Public output must never fail because a language file is missing.
        }
    }
}
