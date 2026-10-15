<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Schema;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\PluginHelper;

/**
 * Decides where the JSON-LD graph is published.
 *
 * A configured mode that names an unavailable provider degrades to standalone,
 * so the schema is always emitted somewhere rather than silently disappearing.
 */
final class OutputMode
{
    public const AUTO = 'auto';
    public const CORE = 'core';
    public const FORSEO = 'forseo';
    public const STANDALONE = 'standalone';

    public const ALL = [self::AUTO, self::CORE, self::FORSEO, self::STANDALONE];

    public static function coreAvailable(): bool
    {
        return PluginHelper::isEnabled('system', 'schemaorg');
    }

    public static function forSeoAvailable(): bool
    {
        return PluginHelper::isEnabled('system', 'forseo');
    }

    /**
     * In auto mode 4SEO wins over the core Schema.org plugin: a site that runs
     * 4SEO uses it as its main SEO stack, and its graph is the richer one.
     */
    public static function resolve(array $options): string
    {
        $mode = (string) ($options['schema_mode'] ?? self::AUTO);

        if (!in_array($mode, self::ALL, true)) {
            $mode = self::AUTO;
        }

        if ($mode === self::AUTO) {
            if (self::forSeoAvailable()) {
                return self::FORSEO;
            }

            return self::coreAvailable() ? self::CORE : self::STANDALONE;
        }

        if ($mode === self::FORSEO && !self::forSeoAvailable()) {
            return self::STANDALONE;
        }

        if ($mode === self::CORE && !self::coreAvailable()) {
            return self::STANDALONE;
        }

        return $mode;
    }
}
