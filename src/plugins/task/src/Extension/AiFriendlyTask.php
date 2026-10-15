<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Plugin\Task\AiFriendly\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
use Sernicola\Library\AiFriendly\Service\Factory as AiFactory;
use Sernicola\Library\AiFriendly\Service\Regenerator;
use Sernicola\Library\AiFriendly\Support\Options;

final class AiFriendlyTask extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    protected $autoloadLanguage = true;
    protected const TASKS_MAP = [
        'aifriendly.regenerate' => [
            'langConstPrefix' => 'PLG_TASK_AIFRIENDLY_REGENERATE',
            'method' => 'regenerate',
            'form' => 'regenerate',
        ],
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask' => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    private function regenerate(ExecuteTaskEvent $event): int
    {
        $options = Options::get();
        if (empty($options['auto_regenerate']) || empty($options['static_md_files'])) return Status::NO_RUN;
        $params = $event->getArgument('params');
        $limit = max(1, (int) ($params->batch_size ?? $options['regenerate_batch_size'] ?? 100));

        // The task parameter is the starting point; the cursor is where the previous
        // run stopped, so consecutive runs walk the whole catalogue instead of
        // reprocessing the same batch forever.
        $start = max(0, (int) ($params->offset ?? 0));
        $cursor = max($start, (int) ($options['regenerate_cursor'] ?? 0));

        $repository = AiFactory::repository();
        $registry = AiFactory::registry();
        $stats = (new Regenerator($registry, AiFactory::rules($repository), AiFactory::staticStore($repository), $repository, $options))->run($limit, $cursor, false, 'scheduler');

        Options::save(['regenerate_cursor' => $stats['finished'] ? $start : (int) $stats['next_offset']]);
        $this->logTask('AI Friendly: ' . json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $stats['errors'] > 0 ? Status::KNOCKOUT : Status::OK;
    }
}
