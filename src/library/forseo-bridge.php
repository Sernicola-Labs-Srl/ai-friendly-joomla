<?php

/**
 * AI Friendly — 4SEO bridge.
 *
 * This file is meant to be included from the weeblr functions file:
 *
 *     libraries/weeblr/forseo_functions.php
 *
 * by adding the single line shown in the Hub, Schema section:
 *
 *     include JPATH_LIBRARIES . '/aifriendly/forseo-bridge.php';
 *
 * 4SEO exposes $hooks only inside that file, which is why the registration
 * cannot live in a Joomla plugin of ours.
 *
 * Nothing happens unless Semantic Schema is enabled and its output mode
 * resolves to 4SEO, so leaving the include in place is harmless.
 */

defined('WBLIB_EXEC') || defined('_JEXEC') || die;

/** @var object $hooks provided by the 4SEO functions file */
if (!isset($hooks) || !is_object($hooks) || !method_exists($hooks, 'add')) {
    return;
}

if (!class_exists(\Sernicola\Library\AiFriendly\Integration\ForSeo::class)) {
    return;
}

$hooks->add('forseo_structured_data', static function ($data) {
    try {
        return \Sernicola\Library\AiFriendly\Integration\ForSeo::inject($data);
    } catch (\Throwable) {
        // A fault on our side must never break the structured data of the site.
        return $data;
    }
});
