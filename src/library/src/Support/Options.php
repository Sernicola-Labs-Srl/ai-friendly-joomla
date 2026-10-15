<?php

namespace Sernicola\Library\AiFriendly\Support;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

final class Options
{
    public static function defaults(): array
    {
        return [
            'llms_content' => '', 'llms_include_auto' => 1,
            'include_articles' => 1, 'include_categories' => 1, 'articles_limit' => 100,
            // New content types default to off: an upgrade must not start
            // publishing contacts and tag pages without the site asking for it.
            'include_contacts' => 0, 'include_tags' => 0, 'contact_include_email' => 0,
            'exclude_categories' => [], 'exclude_tags' => [],
            'exclude_url_patterns' => '', 'exclude_noindex' => 1,
            'include_metadata' => 1, 'normalize_headings' => 1, 'render_content_plugins' => 1,
            'link_to_markdown' => 0,
            // Seconds. 0 keeps every response uncached, which is the safe default
            // for a public endpoint.
            'dynamic_cache_ttl' => 0,
            'static_md_files' => 0, 'auto_regenerate' => 0,
            'regenerate_batch_size' => 100, 'regenerate_on_save' => 1, 'regenerate_on_change' => 1,
            'regenerate_cursor' => 0,
            'notify_email' => 0, 'notify_email_to' => '',
            'onboarding_done' => 0,
            'schema_enabled' => 0, 'schema_mode' => 'auto',
            'schema_creator_type' => 'Organization', 'schema_creator_name' => '', 'schema_creator_url' => '',
            'schema_entity_type' => 'Person', 'schema_name' => '', 'schema_alternate_name' => '',
            'schema_description' => '', 'schema_disambiguating_description' => '', 'schema_job_title' => '',
            'schema_additional_type' => '', 'schema_slogan' => '', 'schema_founding_date' => '',
            'schema_legal_name' => '', 'schema_vat_id' => '', 'schema_tax_id' => '', 'schema_lei_code' => '',
            'schema_ticker_symbol' => '', 'schema_logo_url' => '', 'schema_image_url' => '',
            'schema_street_address' => '', 'schema_postal_code' => '', 'schema_address_locality' => '',
            'schema_address_region' => '', 'schema_address_country' => '', 'schema_contact_type' => '',
            'schema_contact_email' => '', 'schema_contact_languages' => '', 'schema_founders' => '',
            'schema_area_served' => '', 'schema_services' => [], 'schema_same_as' => '',
            'schema_knows_about' => '', 'schema_knows_language' => '', 'schema_license' => '',
            'schema_profile_page_url' => '',
            'schema_extra_types' => '', 'schema_telephone' => '', 'schema_price_range' => '',
            'schema_opening_hours' => '', 'schema_geo_latitude' => '', 'schema_geo_longitude' => '',
            'schema_has_map' => '', 'schema_search_url' => '', 'schema_page_rules' => '',
        ];
    }

    public static function get(): array
    {
        $params = ComponentHelper::getParams('com_aifriendly');

        return array_replace(self::defaults(), $params->toArray());
    }

    public static function registry(): Registry
    {
        return new Registry(self::get());
    }

    /**
     * Merges $changes into the stored component parameters.
     *
     * Shared by the Hub controller and the scheduled task, which persists its
     * regeneration cursor between runs.
     */
    public static function save(array $changes): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $merged = array_replace(self::get(), $changes);
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_aifriendly'));
        $db->setQuery($query)->execute();
    }
}

