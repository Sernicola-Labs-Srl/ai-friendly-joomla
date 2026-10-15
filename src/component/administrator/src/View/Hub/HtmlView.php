<?php

namespace Sernicola\Component\AiFriendly\Administrator\View\Hub;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Sernicola\Library\AiFriendly\Integration\ForSeo;
use Sernicola\Library\AiFriendly\Schema\OutputMode;
use Sernicola\Library\AiFriendly\Service\Diagnostics;
use Sernicola\Library\AiFriendly\Service\Factory as AiFactory;
use Sernicola\Library\AiFriendly\Support\Options;

final class HtmlView extends BaseHtmlView
{
    public const VERSION = '2.2.0';

    public array $options = [];
    public array $overview = [];
    public string $version = self::VERSION;

    /** Status of the 4SEO bridge, rendered in the Schema section. */
    public array $forseo = [];

    /**
     * Strings handed to hub.js through Joomla.Text.
     */
    private const SCRIPT_STRINGS = [
        'COM_AIFRIENDLY_GENERIC_ERROR', 'COM_AIFRIENDLY_SAVED', 'COM_AIFRIENDLY_WORKING',
        'COM_AIFRIENDLY_DISMISS', 'COM_AIFRIENDLY_RELOAD', 'COM_AIFRIENDLY_UNSAVED',
        'COM_AIFRIENDLY_DIAGNOSTICS_EMPTY', 'COM_AIFRIENDLY_TIMELINE_EMPTY',
        'COM_AIFRIENDLY_LEVEL_ERROR', 'COM_AIFRIENDLY_LEVEL_WARNING', 'COM_AIFRIENDLY_LEVEL_INFO',
        'COM_AIFRIENDLY_DIAG_EMPTY_SCOPE', 'COM_AIFRIENDLY_DIAG_STATIC_NOT_WRITABLE',
        'COM_AIFRIENDLY_DIAG_AUTOMATION_DISABLED', 'COM_AIFRIENDLY_DIAG_SCHEMA_MISSING_NAME',
        'COM_AIFRIENDLY_DIAG_DUPLICATE_PATTERNS', 'COM_AIFRIENDLY_DIAG_ROBOTS_DISABLED',
        'COM_AIFRIENDLY_DIAG_FORSEO_BRIDGE_MISSING', 'COM_AIFRIENDLY_DIAG_SCHEMA_TWO_PRODUCERS',
        'COM_AIFRIENDLY_DIAG_NO_CONTENT_TYPE', 'COM_AIFRIENDLY_DIAG_SEF_DISABLED', 'COM_AIFRIENDLY_DIAG_SCHEMA_MISSING_SAMEAS', 'COM_AIFRIENDLY_DIAG_NOTIFY_EMAIL_INVALID', 'COM_AIFRIENDLY_DIAG_STATIC_NEVER_GENERATED', 'COM_AIFRIENDLY_DIAG_LAST_RUN_ERRORS', 'COM_AIFRIENDLY_DIAG_TASK_NOT_SCHEDULED',
        'COM_AIFRIENDLY_COPIED',
        'COM_AIFRIENDLY_EVENT_SETTINGS_SAVE', 'COM_AIFRIENDLY_EVENT_CONTENT_EXCLUSION_TOGGLE',
        'COM_AIFRIENDLY_EVENT_CONTENT_STATE_CHANGE', 'COM_AIFRIENDLY_EVENT_LLMS_SNAPSHOT_CREATE',
        'COM_AIFRIENDLY_EVENT_LLMS_SNAPSHOT_RESTORE', 'COM_AIFRIENDLY_EVENT_REGENERATION',
        'COM_AIFRIENDLY_EVENT_REGENERATE_ON_SAVE', 'COM_AIFRIENDLY_EVENT_REGENERATE_ON_SAVE_ERROR',
        'COM_AIFRIENDLY_EVENT_STATIC_VERSIONS_CLEAR',
        'COM_AIFRIENDLY_PREVIEW_LINES', 'COM_AIFRIENDLY_PREVIEW_TOKENS',
        'COM_AIFRIENDLY_PREVIEW_LINKS', 'COM_AIFRIENDLY_PREVIEW_LINKS_INVALID',
        'COM_AIFRIENDLY_SNAPSHOT_CREATED', 'COM_AIFRIENDLY_SNAPSHOTS_EMPTY',
        'COM_AIFRIENDLY_SNAPSHOT_RESTORE', 'COM_AIFRIENDLY_SNAPSHOT_RESTORE_LABEL',
        'COM_AIFRIENDLY_SNAPSHOT_RESTORE_CONFIRM', 'COM_AIFRIENDLY_SNAPSHOT_RESTORED',
        'COM_AIFRIENDLY_SNAPSHOT_SELECT', 'COM_AIFRIENDLY_SNAPSHOT_SELECT_ONE',
        'COM_AIFRIENDLY_SNAPSHOT_SELECT_MAX', 'COM_AIFRIENDLY_SNAPSHOT_META',
        'COM_AIFRIENDLY_SNAPSHOT_COMPARE_LABEL',
        'COM_AIFRIENDLY_DIFF_LINE', 'COM_AIFRIENDLY_DIFF_LEFT', 'COM_AIFRIENDLY_DIFF_RIGHT',
        'COM_AIFRIENDLY_DIFF_IDENTICAL',
        'COM_AIFRIENDLY_CONTENT_EMPTY', 'COM_AIFRIENDLY_CONTENT_LOADING', 'COM_AIFRIENDLY_RESULTS',
        'COM_AIFRIENDLY_STATUS_INCLUDED', 'COM_AIFRIENDLY_STATUS_EXCLUDED_MANUAL',
        'COM_AIFRIENDLY_STATUS_EXCLUDED_RULES',
        'COM_AIFRIENDLY_ACTION_INCLUDE', 'COM_AIFRIENDLY_ACTION_EXCLUDE',
        'COM_AIFRIENDLY_ACTION_INCLUDE_LABEL', 'COM_AIFRIENDLY_ACTION_EXCLUDE_LABEL',
        'COM_AIFRIENDLY_PAGE_OF',
        'COM_AIFRIENDLY_CLEAR_CONFIRM', 'COM_AIFRIENDLY_CLEARED',
        'COM_AIFRIENDLY_PRUNED', 'COM_AIFRIENDLY_PRUNE_SKIPPED', 'COM_AIFRIENDLY_EVENT_STATIC_VERSIONS_PRUNE',
        'COM_AIFRIENDLY_RUN_PENDING', 'COM_AIFRIENDLY_RUN_DONE', 'COM_AIFRIENDLY_RUN_PROCESSED',
        'COM_AIFRIENDLY_RUN_REGENERATED', 'COM_AIFRIENDLY_RUN_SKIPPED',
        'COM_AIFRIENDLY_RUN_EXCLUDED', 'COM_AIFRIENDLY_RUN_ERRORS',
        'COM_AIFRIENDLY_OUTLINE_COUNT', 'COM_AIFRIENDLY_OUTLINE_EMPTY', 'COM_AIFRIENDLY_LINKCHECK_EMPTY', 'COM_AIFRIENDLY_LINKCHECK_SPACE', 'COM_AIFRIENDLY_LINKCHECK_SCHEME', 'COM_AIFRIENDLY_LINKCHECK_LABEL', 'COM_AIFRIENDLY_LINE_N',
        'COM_AIFRIENDLY_WIZARD_BASE_DONE', 'COM_AIFRIENDLY_WIZARD_ALL_DONE',
    ];

    public function display($tpl = null): void
    {
        $app = Factory::getApplication();

        if (!$app->getIdentity()->authorise('core.manage', 'com_aifriendly')) {
            throw new \RuntimeException('JERROR_ALERTNOAUTHOR', 403);
        }

        $repository = AiFactory::repository();
        $registry = AiFactory::registry();
        $rules = AiFactory::rules($repository);
        $this->options = Options::get();
        $this->overview = (new Diagnostics($registry, $rules, AiFactory::staticStore($repository), $repository, $this->options))->run();

        $this->forseo = [
            'available'  => OutputMode::forSeoAvailable(),
            'core'       => OutputMode::coreAvailable(),
            'registered' => ForSeo::bridgeRegistered(),
            'file'       => ForSeo::functionsFile(),
            'snippet'    => ForSeo::snippet(),
            'effective'  => OutputMode::resolve($this->options),
        ];

        ToolbarHelper::title(Text::_('COM_AIFRIENDLY'), 'dashboard');

        foreach (self::SCRIPT_STRINGS as $key) {
            Text::script($key);
        }

        $app->getDocument()->getWebAssetManager()->useStyle('com_aifriendly.hub')->useScript('com_aifriendly.hub');

        parent::display($tpl);
    }
}
