<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Sernicola\Component\AiFriendly\Administrator\View\Hub\HtmlView $this */

$o        = $this->options;
$overview = $this->overview;
$language = Factory::getApplication()->getLanguage();

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$checked = static fn (string $key): string => !empty($o[$key]) ? ' checked' : '';

$raw = static function (string $key) use ($o) {
    $value = $o[$key] ?? '';

    if (is_array($value)) {
        return $key === 'schema_services'
            ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : implode(',', $value);
    }

    return (string) $value;
};

// Diagnostics and events carry stable codes: translate them, fall back to the server text.
$translate = static function (string $prefix, string $code, string $fallback) use ($language): string {
    $key = $prefix . strtoupper($code);

    return $code !== '' && $language->hasKey($key) ? Text::_($key) : $fallback;
};

$sections = [
    'overview'   => ['01', 'COM_AIFRIENDLY_SECTION_OVERVIEW', 'COM_AIFRIENDLY_SECTION_OVERVIEW_DESC'],
    'content'    => ['02', 'COM_AIFRIENDLY_SECTION_CONTENT', 'COM_AIFRIENDLY_SECTION_CONTENT_DESC'],
    'rules'      => ['03', 'COM_AIFRIENDLY_SECTION_RULES', 'COM_AIFRIENDLY_SECTION_RULES_DESC'],
    'schema'     => ['04', 'COM_AIFRIENDLY_SECTION_SCHEMA', 'COM_AIFRIENDLY_SECTION_SCHEMA_DESC'],
    'automation' => ['05', 'COM_AIFRIENDLY_SECTION_AUTOMATION', 'COM_AIFRIENDLY_SECTION_AUTOMATION_DESC'],
];

$sectionHeader = static function (string $key) use ($sections, $e): string {
    [$number, $label, $description] = $sections[$key];

    return '<header class="aif-section__header">'
        . '<span aria-hidden="true">' . $number . '</span>'
        . '<div><h2 id="aif-heading-' . $key . '">' . $e(Text::_($label)) . '</h2>'
        . '<p>' . $e(Text::_($description)) . '</p></div>'
        . '</header>';
};

/**
 * Renders one labelled control of the settings grid.
 *
 * @param array{key:string,label:string,type?:string,rows?:int,hint?:string,attrs?:string} $definition
 */
$field = static function (array $definition) use ($e, $raw): string {
    $key  = $definition['key'];
    $type = $definition['type'] ?? 'text';
    $id   = 'aif-' . str_replace('_', '-', $key);
    $wide = $type === 'textarea';
    $hint = '';
    $aria = '';

    if (!empty($definition['hint'])) {
        $hint = '<small class="aif-hint" id="' . $id . '-hint">' . $e(Text::_($definition['hint'])) . '</small>';
        $aria = ' aria-describedby="' . $id . '-hint"';
    }

    $control = $type === 'textarea'
        ? '<textarea id="' . $id . '" name="options[' . $key . ']" rows="' . (int) ($definition['rows'] ?? 4) . '"' . $aria . '>' . $e($raw($key)) . '</textarea>'
        : '<input id="' . $id . '" type="' . $e($type) . '" name="options[' . $key . ']" value="' . $e($raw($key)) . '"' . ($definition['attrs'] ?? '') . $aria . '>';

    return '<div class="aif-field' . ($wide ? ' aif-field--wide' : '') . '">'
        . '<label for="' . $id . '">' . $e(Text::_($definition['label'])) . '</label>'
        . $control . $hint . '</div>';
};

$toggle = static function (string $key, string $label, ?string $hint = null) use ($e, $checked): string {
    $id   = 'aif-' . str_replace('_', '-', $key);
    $aria = $hint ? ' aria-describedby="' . $id . '-hint"' : '';

    return '<div class="aif-field aif-field--toggle">'
        . '<div class="aif-check"><input type="checkbox" id="' . $id . '" name="options[' . $key . ']" value="1"' . $checked($key) . $aria . '>'
        . '<label for="' . $id . '">' . $e(Text::_($label)) . '</label></div>'
        . ($hint ? '<small class="aif-hint" id="' . $id . '-hint">' . $e(Text::_($hint)) . '</small>' : '')
        . '</div>';
};

$schemaGroups = [
    'COM_AIFRIENDLY_SCHEMA_GROUP_IDENTITY' => [
        ['key' => 'schema_name', 'label' => 'COM_AIFRIENDLY_SCHEMA_NAME', 'hint' => 'COM_AIFRIENDLY_SCHEMA_NAME_HINT'],
        ['key' => 'schema_alternate_name', 'label' => 'COM_AIFRIENDLY_SCHEMA_ALTERNATE_NAME'],
        ['key' => 'schema_job_title', 'label' => 'COM_AIFRIENDLY_SCHEMA_JOB_TITLE'],
        ['key' => 'schema_additional_type', 'label' => 'COM_AIFRIENDLY_SCHEMA_ADDITIONAL_TYPE'],
        ['key' => 'schema_slogan', 'label' => 'COM_AIFRIENDLY_SCHEMA_SLOGAN'],
        ['key' => 'schema_founding_date', 'label' => 'COM_AIFRIENDLY_SCHEMA_FOUNDING_DATE', 'hint' => 'COM_AIFRIENDLY_SCHEMA_FOUNDING_DATE_HINT', 'attrs' => ' placeholder="2019-04-23"'],
        ['key' => 'schema_extra_types', 'label' => 'COM_AIFRIENDLY_SCHEMA_EXTRA_TYPES', 'type' => 'textarea', 'hint' => 'COM_AIFRIENDLY_SCHEMA_EXTRA_TYPES_HINT'],
        ['key' => 'schema_search_url', 'label' => 'COM_AIFRIENDLY_SCHEMA_SEARCH_URL', 'type' => 'url', 'hint' => 'COM_AIFRIENDLY_SCHEMA_SEARCH_URL_HINT'],
    ],
    'COM_AIFRIENDLY_SCHEMA_GROUP_LEGAL' => [
        ['key' => 'schema_legal_name', 'label' => 'COM_AIFRIENDLY_SCHEMA_LEGAL_NAME'],
        ['key' => 'schema_vat_id', 'label' => 'COM_AIFRIENDLY_SCHEMA_VAT_ID'],
        ['key' => 'schema_tax_id', 'label' => 'COM_AIFRIENDLY_SCHEMA_TAX_ID'],
        ['key' => 'schema_lei_code', 'label' => 'COM_AIFRIENDLY_SCHEMA_LEI_CODE'],
        ['key' => 'schema_ticker_symbol', 'label' => 'COM_AIFRIENDLY_SCHEMA_TICKER_SYMBOL'],
    ],
    'COM_AIFRIENDLY_SCHEMA_GROUP_MEDIA' => [
        ['key' => 'schema_logo_url', 'label' => 'COM_AIFRIENDLY_SCHEMA_LOGO_URL', 'type' => 'url'],
        ['key' => 'schema_image_url', 'label' => 'COM_AIFRIENDLY_SCHEMA_IMAGE_URL', 'type' => 'url'],
        ['key' => 'schema_profile_page_url', 'label' => 'COM_AIFRIENDLY_SCHEMA_PROFILE_PAGE_URL', 'type' => 'url'],
        ['key' => 'schema_license', 'label' => 'COM_AIFRIENDLY_SCHEMA_LICENSE', 'type' => 'url'],
    ],
    'COM_AIFRIENDLY_SCHEMA_GROUP_TEXT' => [
        ['key' => 'schema_description', 'label' => 'COM_AIFRIENDLY_SCHEMA_DESCRIPTION', 'type' => 'textarea'],
        ['key' => 'schema_disambiguating_description', 'label' => 'COM_AIFRIENDLY_SCHEMA_DISAMBIGUATING_DESCRIPTION', 'type' => 'textarea'],
        ['key' => 'schema_same_as', 'label' => 'COM_AIFRIENDLY_SCHEMA_SAME_AS', 'type' => 'textarea', 'hint' => 'COM_AIFRIENDLY_SCHEMA_SAME_AS_HINT'],
        ['key' => 'schema_knows_about', 'label' => 'COM_AIFRIENDLY_SCHEMA_KNOWS_ABOUT', 'type' => 'textarea', 'hint' => 'COM_AIFRIENDLY_ONE_PER_LINE'],
        ['key' => 'schema_knows_language', 'label' => 'COM_AIFRIENDLY_SCHEMA_KNOWS_LANGUAGE', 'type' => 'textarea', 'hint' => 'COM_AIFRIENDLY_ONE_PER_LINE'],
        ['key' => 'schema_area_served', 'label' => 'COM_AIFRIENDLY_SCHEMA_AREA_SERVED', 'type' => 'textarea', 'hint' => 'COM_AIFRIENDLY_ONE_PER_LINE'],
        ['key' => 'schema_founders', 'label' => 'COM_AIFRIENDLY_SCHEMA_FOUNDERS', 'type' => 'textarea', 'hint' => 'COM_AIFRIENDLY_SCHEMA_FOUNDERS_HINT'],
        ['key' => 'schema_services', 'label' => 'COM_AIFRIENDLY_SCHEMA_SERVICES', 'type' => 'textarea', 'rows' => 6, 'hint' => 'COM_AIFRIENDLY_SCHEMA_SERVICES_HINT'],
        ['key' => 'schema_page_rules', 'label' => 'COM_AIFRIENDLY_SCHEMA_PAGE_RULES', 'type' => 'textarea', 'rows' => 8, 'hint' => 'COM_AIFRIENDLY_SCHEMA_PAGE_RULES_HINT'],
    ],
    'COM_AIFRIENDLY_SCHEMA_GROUP_ADDRESS' => [
        ['key' => 'schema_street_address', 'label' => 'COM_AIFRIENDLY_SCHEMA_STREET_ADDRESS'],
        ['key' => 'schema_postal_code', 'label' => 'COM_AIFRIENDLY_SCHEMA_POSTAL_CODE'],
        ['key' => 'schema_address_locality', 'label' => 'COM_AIFRIENDLY_SCHEMA_ADDRESS_LOCALITY'],
        ['key' => 'schema_address_region', 'label' => 'COM_AIFRIENDLY_SCHEMA_ADDRESS_REGION'],
        ['key' => 'schema_address_country', 'label' => 'COM_AIFRIENDLY_SCHEMA_ADDRESS_COUNTRY'],
        ['key' => 'schema_telephone', 'label' => 'COM_AIFRIENDLY_SCHEMA_TELEPHONE'],
        ['key' => 'schema_price_range', 'label' => 'COM_AIFRIENDLY_SCHEMA_PRICE_RANGE'],
        ['key' => 'schema_geo_latitude', 'label' => 'COM_AIFRIENDLY_SCHEMA_GEO_LATITUDE'],
        ['key' => 'schema_geo_longitude', 'label' => 'COM_AIFRIENDLY_SCHEMA_GEO_LONGITUDE'],
        ['key' => 'schema_has_map', 'label' => 'COM_AIFRIENDLY_SCHEMA_HAS_MAP', 'type' => 'url'],
        ['key' => 'schema_opening_hours', 'label' => 'COM_AIFRIENDLY_SCHEMA_OPENING_HOURS', 'type' => 'textarea', 'hint' => 'COM_AIFRIENDLY_SCHEMA_OPENING_HOURS_HINT'],
    ],
    'COM_AIFRIENDLY_SCHEMA_GROUP_CONTACT' => [
        ['key' => 'schema_contact_type', 'label' => 'COM_AIFRIENDLY_SCHEMA_CONTACT_TYPE'],
        ['key' => 'schema_contact_email', 'label' => 'COM_AIFRIENDLY_SCHEMA_CONTACT_EMAIL', 'type' => 'email'],
        ['key' => 'schema_contact_languages', 'label' => 'COM_AIFRIENDLY_SCHEMA_CONTACT_LANGUAGES'],
    ],
    'COM_AIFRIENDLY_SCHEMA_GROUP_CREDITS' => [
        ['key' => 'schema_creator_name', 'label' => 'COM_AIFRIENDLY_SCHEMA_CREATOR_NAME'],
        ['key' => 'schema_creator_url', 'label' => 'COM_AIFRIENDLY_SCHEMA_CREATOR_URL', 'type' => 'url'],
    ],
];

// The identity group is rendered inline, after the two select controls.
$identityFields = $schemaGroups['COM_AIFRIENDLY_SCHEMA_GROUP_IDENTITY'];
$remainingGroups = array_diff_key($schemaGroups, ['COM_AIFRIENDLY_SCHEMA_GROUP_IDENTITY' => true]);

$alerts = array_merge($overview['errors'] ?? [], $overview['warnings'] ?? []);
?>
<div class="aif-shell" data-aif-hub>
    <?php echo HTMLHelper::_('form.token'); ?>

    <header class="aif-masthead">
        <div class="aif-masthead__identity">
            <p class="aif-version"><?php echo $e(Text::sprintf('COM_AIFRIENDLY_VERSION', $this->version)); ?></p>
            <h1><?php echo $e(Text::_('COM_AIFRIENDLY_HUB_TITLE')); ?></h1>
        </div>
        <div class="aif-masthead__status">
            <p class="aif-masthead__count">
                <strong data-metric="included"><?php echo (int) ($overview['included_count'] ?? 0); ?></strong>
                <span><?php echo $e(Text::_('COM_AIFRIENDLY_MASTHEAD_INCLUDED')); ?></span>
            </p>
            <a class="aif-linkout" href="<?php echo $e($overview['llms_url'] ?? '/llms.txt'); ?>" target="_blank" rel="noopener">
                <?php echo $e(Text::_('COM_AIFRIENDLY_OPEN_LLMS')); ?>
                <span aria-hidden="true">&#8599;</span>
                <span class="aif-sr-only">(<?php echo $e(Text::_('COM_AIFRIENDLY_NEW_TAB')); ?>)</span>
            </a>
        </div>
    </header>

    <?php if (empty($o['onboarding_done'])) : ?>
        <section class="aif-wizard" data-wizard aria-labelledby="aif-wizard-title">
            <div class="aif-wizard__number" aria-hidden="true">00</div>
            <div class="aif-wizard__body">
                <h2 id="aif-wizard-title"><?php echo $e(Text::_('COM_AIFRIENDLY_WIZARD_TITLE')); ?></h2>
                <p><?php echo $e(Text::_('COM_AIFRIENDLY_WIZARD_INTRO')); ?></p>
                <div class="aif-wizard__steps">
                    <button type="button" data-wizard-preset="base"><?php echo $e(Text::_('COM_AIFRIENDLY_WIZARD_PRESET_BASE')); ?></button>
                    <button type="button" data-wizard-preset="all"><?php echo $e(Text::_('COM_AIFRIENDLY_WIZARD_PRESET_ALL')); ?></button>
                    <button type="button" data-wizard-generate><?php echo $e(Text::_('COM_AIFRIENDLY_WIZARD_GENERATE')); ?></button>
                    <button type="button" class="aif-wizard__skip" data-wizard-skip><?php echo $e(Text::_('COM_AIFRIENDLY_WIZARD_SKIP')); ?></button>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <div class="aif-grid">
        <nav class="aif-rail" aria-label="<?php echo $e(Text::_('COM_AIFRIENDLY_NAV_LABEL')); ?>">
            <div class="aif-rail__list" role="tablist" aria-orientation="vertical">
                <?php foreach ($sections as $key => [$number, $label, $description]) : ?>
                    <?php $active = $key === 'overview'; ?>
                    <button type="button"
                            role="tab"
                            id="aif-tab-<?php echo $key; ?>"
                            class="aif-rail__item<?php echo $active ? ' is-active' : ''; ?>"
                            aria-controls="aif-panel-<?php echo $key; ?>"
                            aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
                            tabindex="<?php echo $active ? '0' : '-1'; ?>"
                            data-section-target="<?php echo $key; ?>">
                        <b aria-hidden="true"><?php echo $number; ?></b>
                        <span><?php echo $e(Text::_($label)); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </nav>

        <main class="aif-main">
            <section class="aif-section is-active" id="aif-panel-overview" role="tabpanel" aria-labelledby="aif-tab-overview" tabindex="0" data-section="overview">
                <?php echo $sectionHeader('overview'); ?>

                <div class="aif-metrics">
                    <article>
                        <strong data-metric="included"><?php echo (int) ($overview['included_count'] ?? 0); ?></strong>
                        <span><?php echo $e(Text::_('COM_AIFRIENDLY_METRIC_INCLUDED')); ?></span>
                        <small><?php echo $e(Text::_('COM_AIFRIENDLY_METRIC_INCLUDED_HINT')); ?></small>
                    </article>
                    <article>
                        <strong data-metric="total"><?php echo (int) ($overview['total_count'] ?? 0); ?></strong>
                        <span><?php echo $e(Text::_('COM_AIFRIENDLY_METRIC_TOTAL')); ?></span>
                        <small><?php echo $e(Text::_('COM_AIFRIENDLY_METRIC_TOTAL_HINT')); ?></small>
                    </article>
                    <article>
                        <strong data-metric="static"><?php echo (int) ($overview['static']['count'] ?? 0); ?></strong>
                        <span><?php echo $e(Text::_('COM_AIFRIENDLY_METRIC_STATIC')); ?></span>
                        <small><?php echo $e(Text::_('COM_AIFRIENDLY_METRIC_STATIC_HINT')); ?></small>
                    </article>
                    <article<?php echo $alerts ? ' class="is-flagged"' : ''; ?> data-metric-card="warnings">
                        <strong data-metric="warnings"><?php echo count($alerts); ?></strong>
                        <span><?php echo $e(Text::_('COM_AIFRIENDLY_METRIC_ALERTS')); ?></span>
                        <small><?php echo $e(Text::_('COM_AIFRIENDLY_METRIC_ALERTS_HINT')); ?></small>
                    </article>
                </div>

                <div class="aif-split">
                    <article class="aif-panel">
                        <div class="aif-panel__heading">
                            <h3><?php echo $e(Text::_('COM_AIFRIENDLY_DIAGNOSTICS')); ?></h3>
                            <button type="button" data-action="diagnostics"><?php echo $e(Text::_('COM_AIFRIENDLY_DIAGNOSTICS_REFRESH')); ?></button>
                        </div>
                        <ul class="aif-list" data-diagnostics>
                            <?php foreach ($alerts as $warning) : ?>
                                <?php $level = $warning['level'] ?? 'info'; ?>
                                <li data-level="<?php echo $e($level); ?>">
                                    <span class="aif-tag"><?php echo $e(Text::_('COM_AIFRIENDLY_LEVEL_' . strtoupper($level))); ?></span>
                                    <?php echo $e($translate('COM_AIFRIENDLY_DIAG_', (string) ($warning['code'] ?? ''), (string) ($warning['message'] ?? ''))); ?>
                                </li>
                            <?php endforeach; ?>
                            <?php if (!$alerts) : ?>
                                <li class="aif-empty"><?php echo $e(Text::_('COM_AIFRIENDLY_DIAGNOSTICS_EMPTY')); ?></li>
                            <?php endif; ?>
                        </ul>
                    </article>

                    <article class="aif-panel">
                        <div class="aif-panel__heading">
                            <h3><?php echo $e(Text::_('COM_AIFRIENDLY_TIMELINE')); ?></h3>
                            <button type="button" data-action="events"><?php echo $e(Text::_('COM_AIFRIENDLY_TIMELINE_REFRESH')); ?></button>
                        </div>
                        <ol class="aif-list" data-events>
                            <?php foreach ($overview['events'] ?? [] as $event) : ?>
                                <li>
                                    <time><?php echo $e($event['created'] ?? ''); ?></time>
                                    <span><?php echo $e($translate('COM_AIFRIENDLY_EVENT_', (string) ($event['event_type'] ?? ''), (string) ($event['event_type'] ?? ''))); ?></span>
                                </li>
                            <?php endforeach; ?>
                            <?php if (empty($overview['events'])) : ?>
                                <li class="aif-empty"><?php echo $e(Text::_('COM_AIFRIENDLY_TIMELINE_EMPTY')); ?></li>
                            <?php endif; ?>
                        </ol>
                    </article>
                </div>
            </section>

            <section class="aif-section" id="aif-panel-content" role="tabpanel" aria-labelledby="aif-tab-content" tabindex="0" data-section="content" hidden>
                <?php echo $sectionHeader('content'); ?>

                <div class="aif-split aif-split--editor">
                    <article class="aif-panel">
                        <h3><?php echo $e(Text::_('COM_AIFRIENDLY_EDITOR_TITLE')); ?></h3>
                        <div class="aif-field aif-field--wide">
                            <label for="aif-llms-content" class="aif-sr-only"><?php echo $e(Text::_('COM_AIFRIENDLY_EDITOR_LABEL')); ?></label>
                            <textarea id="aif-llms-content" rows="18" name="options[llms_content]" data-llms-editor
                                      aria-describedby="aif-llms-content-hint"
                                      spellcheck="false"><?php echo $e($o['llms_content'] ?? ''); ?></textarea>
                            <small class="aif-hint" id="aif-llms-content-hint"><?php echo $e(Text::_('COM_AIFRIENDLY_EDITOR_HINT')); ?></small>
                        </div>
                        <?php echo $toggle('llms_include_auto', 'COM_AIFRIENDLY_INCLUDE_AUTO'); ?>

                        <div class="aif-editor-tools">
                            <p class="aif-editor-stats" data-editor-stats aria-live="polite"></p>
                            <details class="aif-outline" data-outline-wrap>
                                <summary><?php echo $e(Text::_('COM_AIFRIENDLY_OUTLINE')); ?></summary>
                                <ol class="aif-outline__list" data-outline></ol>
                            </details>
                            <div class="aif-linkcheck" data-linkcheck hidden>
                                <h4><?php echo $e(Text::_('COM_AIFRIENDLY_LINKCHECK')); ?></h4>
                                <ul data-linkcheck-list></ul>
                            </div>
                        </div>

                        <div class="aif-actions">
                            <button type="button" class="aif-primary" data-action="save"><?php echo $e(Text::_('COM_AIFRIENDLY_SAVE')); ?></button>
                            <button type="button" data-action="preview"><?php echo $e(Text::_('COM_AIFRIENDLY_PREVIEW_REFRESH')); ?></button>
                            <button type="button" data-action="snapshot-create"><?php echo $e(Text::_('COM_AIFRIENDLY_SNAPSHOT_CREATE')); ?></button>
                            <span class="aif-dirty" data-dirty hidden><?php echo $e(Text::_('COM_AIFRIENDLY_UNSAVED')); ?></span>
                        </div>
                    </article>

                    <article class="aif-panel">
                        <h3><?php echo $e(Text::_('COM_AIFRIENDLY_PREVIEW')); ?></h3>
                        <p class="aif-preview-meta">
                            <span data-preview-lines><?php echo $e(Text::sprintf('COM_AIFRIENDLY_PREVIEW_LINES', 0)); ?></span>
                            <span data-preview-tokens><?php echo $e(Text::sprintf('COM_AIFRIENDLY_PREVIEW_TOKENS', 0)); ?></span>
                            <span data-preview-links></span>
                        </p>
                        <pre data-preview class="aif-empty"><?php echo $e(Text::_('COM_AIFRIENDLY_PREVIEW_EMPTY')); ?></pre>
                    </article>
                </div>

                <article class="aif-panel">
                    <div class="aif-panel__heading">
                        <h3><?php echo $e(Text::_('COM_AIFRIENDLY_SNAPSHOTS')); ?></h3>
                        <button type="button" data-action="snapshots"><?php echo $e(Text::_('COM_AIFRIENDLY_SNAPSHOTS_REFRESH')); ?></button>
                    </div>
                    <div class="aif-snapshot-layout">
                        <ul class="aif-snapshot-list" data-snapshots></ul>
                        <div class="aif-diff" data-diff>
                            <p class="aif-empty"><?php echo $e(Text::_('COM_AIFRIENDLY_SNAPSHOT_SELECT')); ?></p>
                        </div>
                    </div>
                </article>

                <article class="aif-panel">
                    <div class="aif-panel__heading">
                        <h3><?php echo $e(Text::_('COM_AIFRIENDLY_CONTENT_TITLE')); ?></h3>
                        <div class="aif-search">
                            <label for="aif-content-search" class="aif-sr-only"><?php echo $e(Text::_('COM_AIFRIENDLY_CONTENT_SEARCH_LABEL')); ?></label>
                            <input type="search" id="aif-content-search" data-content-search
                                   placeholder="<?php echo $e(Text::_('COM_AIFRIENDLY_CONTENT_SEARCH_PLACEHOLDER')); ?>">
                            <button type="button" data-action="content"><?php echo $e(Text::_('COM_AIFRIENDLY_CONTENT_SEARCH')); ?></button>
                        </div>
                    </div>
                    <p class="aif-results" data-content-total></p>
                    <div class="aif-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo $e(Text::_('COM_AIFRIENDLY_COL_TITLE')); ?></th>
                                    <th scope="col"><?php echo $e(Text::_('COM_AIFRIENDLY_COL_PROVIDER')); ?></th>
                                    <th scope="col"><?php echo $e(Text::_('COM_AIFRIENDLY_COL_LANGUAGE')); ?></th>
                                    <th scope="col"><?php echo $e(Text::_('COM_AIFRIENDLY_COL_STATUS')); ?></th>
                                    <th scope="col"><span class="aif-sr-only"><?php echo $e(Text::_('COM_AIFRIENDLY_COL_ACTIONS')); ?></span></th>
                                </tr>
                            </thead>
                            <tbody data-content-rows>
                                <tr><td colspan="5" class="aif-empty"><?php echo $e(Text::_('COM_AIFRIENDLY_CONTENT_LOADING')); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <nav class="aif-pagination" aria-label="<?php echo $e(Text::_('COM_AIFRIENDLY_CONTENT_TITLE')); ?>">
                        <button type="button" data-content-prev disabled><?php echo $e(Text::_('COM_AIFRIENDLY_PAGE_PREV')); ?></button>
                        <span data-content-page><?php echo $e(Text::sprintf('COM_AIFRIENDLY_PAGE_OF', 1, 1)); ?></span>
                        <button type="button" data-content-next disabled><?php echo $e(Text::_('COM_AIFRIENDLY_PAGE_NEXT')); ?></button>
                    </nav>
                </article>
            </section>

            <section class="aif-section" id="aif-panel-rules" role="tabpanel" aria-labelledby="aif-tab-rules" tabindex="0" data-section="rules" hidden>
                <?php echo $sectionHeader('rules'); ?>

                <fieldset class="aif-fieldset">
                    <legend><?php echo $e(Text::_('COM_AIFRIENDLY_RULES_SCOPE')); ?></legend>
                    <div class="aif-form-grid">
                        <?php echo $toggle('include_articles', 'COM_AIFRIENDLY_INCLUDE_ARTICLES'); ?>
                        <?php echo $toggle('include_categories', 'COM_AIFRIENDLY_INCLUDE_CATEGORIES'); ?>
                        <?php echo $toggle('include_contacts', 'COM_AIFRIENDLY_INCLUDE_CONTACTS'); ?>
                        <?php echo $toggle('include_tags', 'COM_AIFRIENDLY_INCLUDE_TAGS', 'COM_AIFRIENDLY_INCLUDE_TAGS_HINT'); ?>
                        <?php echo $toggle('contact_include_email', 'COM_AIFRIENDLY_CONTACT_EMAIL', 'COM_AIFRIENDLY_CONTACT_EMAIL_HINT'); ?>
                        <?php echo $field(['key' => 'articles_limit', 'label' => 'COM_AIFRIENDLY_ARTICLES_LIMIT', 'type' => 'number', 'hint' => 'COM_AIFRIENDLY_ARTICLES_LIMIT_HINT', 'attrs' => ' min="1" max="1000" step="1"']); ?>
                    </div>
                </fieldset>

                <fieldset class="aif-fieldset">
                    <legend><?php echo $e(Text::_('COM_AIFRIENDLY_RULES_EXCLUSIONS')); ?></legend>
                    <div class="aif-form-grid">
                        <?php echo $field(['key' => 'exclude_categories', 'label' => 'COM_AIFRIENDLY_EXCLUDE_CATEGORIES', 'hint' => 'COM_AIFRIENDLY_IDS_HINT', 'attrs' => ' inputmode="numeric" placeholder="12,34"']); ?>
                        <?php echo $field(['key' => 'exclude_tags', 'label' => 'COM_AIFRIENDLY_EXCLUDE_TAGS', 'hint' => 'COM_AIFRIENDLY_IDS_HINT', 'attrs' => ' inputmode="numeric" placeholder="5,9"']); ?>
                        <?php echo $toggle('exclude_noindex', 'COM_AIFRIENDLY_EXCLUDE_NOINDEX'); ?>
                        <?php echo $field(['key' => 'exclude_url_patterns', 'label' => 'COM_AIFRIENDLY_EXCLUDE_URL_PATTERNS', 'type' => 'textarea', 'rows' => 6, 'hint' => 'COM_AIFRIENDLY_EXCLUDE_URL_PATTERNS_HINT']); ?>
                    </div>
                </fieldset>

                <fieldset class="aif-fieldset">
                    <legend><?php echo $e(Text::_('COM_AIFRIENDLY_RULES_OUTPUT')); ?></legend>
                    <div class="aif-form-grid">
                        <?php echo $toggle('include_metadata', 'COM_AIFRIENDLY_INCLUDE_METADATA'); ?>
                        <?php echo $toggle('normalize_headings', 'COM_AIFRIENDLY_NORMALIZE_HEADINGS', 'COM_AIFRIENDLY_NORMALIZE_HEADINGS_HINT'); ?>
                        <?php echo $toggle('render_content_plugins', 'COM_AIFRIENDLY_RENDER_CONTENT_PLUGINS', 'COM_AIFRIENDLY_RENDER_CONTENT_PLUGINS_HINT'); ?>
                        <?php echo $toggle('link_to_markdown', 'COM_AIFRIENDLY_LINK_TO_MARKDOWN', 'COM_AIFRIENDLY_LINK_TO_MARKDOWN_HINT'); ?>
                    </div>
                </fieldset>

                <div class="aif-actionbar">
                    <button type="button" class="aif-primary" data-action="save"><?php echo $e(Text::_('COM_AIFRIENDLY_SAVE_RULES')); ?></button>
                    <span class="aif-dirty" data-dirty hidden><?php echo $e(Text::_('COM_AIFRIENDLY_UNSAVED')); ?></span>
                </div>
            </section>

            <section class="aif-section" id="aif-panel-schema" role="tabpanel" aria-labelledby="aif-tab-schema" tabindex="0" data-section="schema" hidden>
                <?php echo $sectionHeader('schema'); ?>

                <div class="aif-check aif-schema-enable">
                    <input type="checkbox" id="aif-schema-enabled" name="options[schema_enabled]" value="1"<?php echo $checked('schema_enabled'); ?> data-schema-toggle>
                    <label for="aif-schema-enabled"><?php echo $e(Text::_('COM_AIFRIENDLY_SCHEMA_ENABLED')); ?></label>
                </div>
                <p class="aif-hint aif-hint--block" data-schema-hint<?php echo !empty($o['schema_enabled']) ? ' hidden' : ''; ?>>
                    <?php echo $e(Text::_('COM_AIFRIENDLY_SCHEMA_OFF_HINT')); ?>
                </p>

                <?php $f = $this->forseo; ?>
                <article class="aif-panel aif-integration">
                    <div class="aif-panel__heading">
                        <h3><?php echo $e(Text::_('COM_AIFRIENDLY_FORSEO_TITLE')); ?></h3>
                        <p class="aif-effective"><?php echo $e(Text::sprintf('COM_AIFRIENDLY_EFFECTIVE_MODE', Text::_('COM_AIFRIENDLY_SCHEMA_MODE_' . strtoupper($f['effective'])))); ?></p>
                    </div>

                    <ul class="aif-checklist">
                        <li data-state="<?php echo $f['available'] ? 'ok' : 'off'; ?>">
                            <?php echo $e(Text::_($f['available'] ? 'COM_AIFRIENDLY_FORSEO_DETECTED' : 'COM_AIFRIENDLY_FORSEO_MISSING')); ?>
                        </li>
                        <li data-state="<?php echo $f['registered'] ? 'ok' : ($f['available'] ? 'todo' : 'off'); ?>">
                            <?php echo $e(Text::_($f['registered'] ? 'COM_AIFRIENDLY_FORSEO_BRIDGE_OK' : 'COM_AIFRIENDLY_FORSEO_BRIDGE_TODO')); ?>
                        </li>
                        <?php if ($f['available'] && $f['core']) : ?>
                            <li data-state="info"><?php echo $e(Text::_('COM_AIFRIENDLY_FORSEO_BOTH_PRODUCERS')); ?></li>
                        <?php endif; ?>
                    </ul>

                    <?php if ($f['available'] && !$f['registered']) : ?>
                        <p class="aif-hint"><?php echo $e(Text::sprintf('COM_AIFRIENDLY_FORSEO_CONSENT', $f['file'])); ?></p>
                        <p><button type="button" data-action="forseo-enable"><?php echo $e(Text::_('COM_AIFRIENDLY_FORSEO_ENABLE')); ?></button></p>
                        <details class="aif-disclosure">
                            <summary><?php echo $e(Text::_('COM_AIFRIENDLY_FORSEO_MANUAL')); ?></summary>
                            <p class="aif-hint"><?php echo $e(Text::sprintf('COM_AIFRIENDLY_FORSEO_INSTRUCTIONS', $f['file'])); ?></p>
                            <div class="aif-snippet">
                                <pre data-forseo-snippet><?php echo $e($f['snippet']); ?></pre>
                                <button type="button" data-copy-target="[data-forseo-snippet]"><?php echo $e(Text::_('COM_AIFRIENDLY_COPY')); ?></button>
                            </div>
                        </details>
                    <?php elseif ($f['available'] && $f['registered']) : ?>
                        <p class="aif-hint"><?php echo $e(Text::sprintf('COM_AIFRIENDLY_FORSEO_REMOVE_HINT', $f['file'])); ?></p>
                        <p><button type="button" data-action="forseo-disable"><?php echo $e(Text::_('COM_AIFRIENDLY_FORSEO_DISABLE')); ?></button></p>
                    <?php endif; ?>
                </article>

                <fieldset class="aif-fieldset">
                    <legend><?php echo $e(Text::_('COM_AIFRIENDLY_SCHEMA_GROUP_IDENTITY')); ?></legend>
                    <div class="aif-form-grid">
                        <div class="aif-field">
                            <label for="aif-schema-mode"><?php echo $e(Text::_('COM_AIFRIENDLY_SCHEMA_MODE')); ?></label>
                            <select id="aif-schema-mode" name="options[schema_mode]" aria-describedby="aif-schema-mode-hint">
                                <?php foreach (['auto' => 'COM_AIFRIENDLY_SCHEMA_MODE_AUTO', 'forseo' => 'COM_AIFRIENDLY_SCHEMA_MODE_FORSEO', 'core' => 'COM_AIFRIENDLY_SCHEMA_MODE_CORE', 'standalone' => 'COM_AIFRIENDLY_SCHEMA_MODE_STANDALONE'] as $value => $label) : ?>
                                    <option value="<?php echo $value; ?>"<?php echo ($o['schema_mode'] ?? '') === $value ? ' selected' : ''; ?>><?php echo $e(Text::_($label)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="aif-hint" id="aif-schema-mode-hint"><?php echo $e(Text::_('COM_AIFRIENDLY_SCHEMA_MODE_HINT')); ?></small>
                        </div>
                        <div class="aif-field">
                            <label for="aif-schema-entity-type"><?php echo $e(Text::_('COM_AIFRIENDLY_SCHEMA_ENTITY_TYPE')); ?></label>
                            <select id="aif-schema-entity-type" name="options[schema_entity_type]">
                                <?php foreach (['Person', 'Organization'] as $value) : ?>
                                    <option value="<?php echo $value; ?>"<?php echo ($o['schema_entity_type'] ?? '') === $value ? ' selected' : ''; ?>><?php echo $value; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php foreach ($identityFields as $definition) : ?>
                            <?php echo $field($definition); ?>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <?php foreach ($remainingGroups as $legend => $definitions) : ?>
                    <fieldset class="aif-fieldset">
                        <legend><?php echo $e(Text::_($legend)); ?></legend>
                        <div class="aif-form-grid">
                            <?php if ($legend === 'COM_AIFRIENDLY_SCHEMA_GROUP_CREDITS') : ?>
                                <div class="aif-field">
                                    <label for="aif-schema-creator-type"><?php echo $e(Text::_('COM_AIFRIENDLY_SCHEMA_CREATOR_TYPE')); ?></label>
                                    <select id="aif-schema-creator-type" name="options[schema_creator_type]">
                                        <?php foreach (['Organization', 'Person'] as $value) : ?>
                                            <option value="<?php echo $value; ?>"<?php echo ($o['schema_creator_type'] ?? '') === $value ? ' selected' : ''; ?>><?php echo $value; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <?php foreach ($definitions as $definition) : ?>
                                <?php echo $field($definition); ?>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>

                <div class="aif-actionbar">
                    <button type="button" class="aif-primary" data-action="save"><?php echo $e(Text::_('COM_AIFRIENDLY_SAVE_SCHEMA')); ?></button>
                    <span class="aif-dirty" data-dirty hidden><?php echo $e(Text::_('COM_AIFRIENDLY_UNSAVED')); ?></span>
                </div>
            </section>

            <section class="aif-section" id="aif-panel-automation" role="tabpanel" aria-labelledby="aif-tab-automation" tabindex="0" data-section="automation" hidden>
                <?php echo $sectionHeader('automation'); ?>

                <fieldset class="aif-fieldset">
                    <legend><?php echo $e(Text::_('COM_AIFRIENDLY_AUTOMATION_FILES')); ?></legend>
                    <div class="aif-form-grid">
                        <?php echo $toggle('static_md_files', 'COM_AIFRIENDLY_STATIC_MD_FILES', 'COM_AIFRIENDLY_STATIC_MD_FILES_HINT'); ?>
                        <?php echo $toggle('regenerate_on_change', 'COM_AIFRIENDLY_REGENERATE_ON_CHANGE', 'COM_AIFRIENDLY_REGENERATE_ON_CHANGE_HINT'); ?>
                        <?php echo $field(['key' => 'dynamic_cache_ttl', 'label' => 'COM_AIFRIENDLY_CACHE_TTL', 'type' => 'number', 'hint' => 'COM_AIFRIENDLY_CACHE_TTL_HINT', 'attrs' => ' min="0" max="86400" step="60"']); ?>
                    </div>
                </fieldset>

                <fieldset class="aif-fieldset">
                    <legend><?php echo $e(Text::_('COM_AIFRIENDLY_AUTOMATION_SCHEDULE')); ?></legend>
                    <div class="aif-form-grid">
                        <?php echo $toggle('auto_regenerate', 'COM_AIFRIENDLY_AUTO_REGENERATE', 'COM_AIFRIENDLY_AUTO_REGENERATE_HINT'); ?>
                        <?php echo $toggle('regenerate_on_save', 'COM_AIFRIENDLY_REGENERATE_ON_SAVE'); ?>
                        <?php echo $field(['key' => 'regenerate_batch_size', 'label' => 'COM_AIFRIENDLY_REGENERATE_BATCH_SIZE', 'type' => 'number', 'hint' => 'COM_AIFRIENDLY_REGENERATE_BATCH_SIZE_HINT', 'attrs' => ' min="1" max="1000" step="1"']); ?>
                    </div>
                </fieldset>

                <fieldset class="aif-fieldset">
                    <legend><?php echo $e(Text::_('COM_AIFRIENDLY_AUTOMATION_NOTIFICATIONS')); ?></legend>
                    <div class="aif-form-grid">
                        <?php echo $toggle('notify_email', 'COM_AIFRIENDLY_NOTIFY_EMAIL'); ?>
                        <?php echo $field(['key' => 'notify_email_to', 'label' => 'COM_AIFRIENDLY_NOTIFY_EMAIL_TO', 'type' => 'email', 'attrs' => ' autocomplete="email"']); ?>
                    </div>
                </fieldset>

                <article class="aif-panel">
                    <h3><?php echo $e(Text::_('COM_AIFRIENDLY_RUN_TITLE')); ?></h3>
                    <div class="aif-actions">
                        <button type="button" data-action="regenerate"><?php echo $e(Text::_('COM_AIFRIENDLY_REGENERATE_NOW')); ?></button>
                        <button type="button" data-action="prune"><?php echo $e(Text::_('COM_AIFRIENDLY_PRUNE')); ?></button>
                        <button type="button" class="aif-danger" data-action="clear"><?php echo $e(Text::_('COM_AIFRIENDLY_CLEAR_STATIC')); ?></button>
                    </div>
                    <div class="aif-run-output" data-run-output aria-live="polite">
                        <p class="aif-empty"><?php echo $e(Text::_('COM_AIFRIENDLY_RUN_EMPTY')); ?></p>
                    </div>
                </article>

                <div class="aif-actionbar">
                    <button type="button" class="aif-primary" data-action="save"><?php echo $e(Text::_('COM_AIFRIENDLY_SAVE_AUTOMATION')); ?></button>
                    <span class="aif-dirty" data-dirty hidden><?php echo $e(Text::_('COM_AIFRIENDLY_UNSAVED')); ?></span>
                </div>
            </section>
        </main>
    </div>

    <?php
        // Inlined rather than served as <img> so the wordmark can follow the
        // admin theme through currentColor: the brand black is invisible on a
        // dark ground. Falls back to the name if the asset is missing.
        $logoFile = JPATH_ROOT . '/media/com_aifriendly/images/sernicola-labs.svg';
        $logo = is_file($logoFile) && is_readable($logoFile) ? (string) file_get_contents($logoFile) : '';
        $logo = $logo === '' ? '' : (string) preg_replace('#<script[^>]*>.*?</script>#si', '', $logo);
    ?>
    <footer class="aif-colophon">
        <a class="aif-colophon__brand" href="https://www.sernicola-labs.com/" target="_blank" rel="noopener">
            <?php if ($logo !== '') : ?>
                <?php echo $logo; ?>
            <?php else : ?>
                <strong><?php echo $e(Text::_('COM_AIFRIENDLY_BRAND')); ?></strong>
            <?php endif; ?>
            <span class="aif-sr-only"><?php echo $e(Text::_('COM_AIFRIENDLY_BRAND')); ?> (<?php echo $e(Text::_('COM_AIFRIENDLY_NEW_TAB')); ?>)</span>
        </a>
        <p><?php echo $e(Text::sprintf('COM_AIFRIENDLY_COLOPHON', $this->version)); ?></p>
    </footer>

    <div class="aif-notice" data-notice role="status" aria-live="polite" aria-atomic="true" hidden>
        <p data-notice-text></p>
        <div class="aif-notice__actions">
            <button type="button" data-notice-action hidden></button>
            <button type="button" class="aif-notice__close" data-notice-close aria-label="<?php echo $e(Text::_('COM_AIFRIENDLY_DISMISS')); ?>">&times;</button>
        </div>
    </div>
</div>
