<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Sernicola\Library\AiFriendly\Markdown\Generator;
use Sernicola\Library\AiFriendly\Rule\RuleEngine;
use Sernicola\Library\AiFriendly\Storage\Repository;
use Sernicola\Library\AiFriendly\Storage\StaticVersionStore;
use Sernicola\Library\AiFriendly\Support\Lang;

final class Regenerator
{
    public function __construct(
        private readonly ContentRegistry $registry,
        private readonly RuleEngine $rules,
        private readonly StaticVersionStore $store,
        private readonly Repository $repository,
        private readonly array $options,
    ) {
    }

    public function run(int $limit = 100, int $offset = 0, bool $force = false, string $trigger = 'manual'): array
    {
        $stats = ['processed' => 0, 'regenerated' => 0, 'skipped' => 0, 'excluded' => 0, 'errors' => 0, 'offset' => $offset];
        $generator = new Generator($this->options);
        foreach ($this->registry->all([], max(1, $limit), max(0, $offset)) as $item) {
            $stats['processed']++;
            if (!$this->rules->allows($item, 'regenerate')) {
                $stats['excluded']++;
                continue;
            }
            try {
                $markdown = $generator->generate($item);
                $state = $this->repository->getState($item->key());
                $checksum = hash('sha256', $markdown);
                if (!$force && !empty($this->options['regenerate_on_change']) && ($state['checksum'] ?? '') === $checksum && is_file((string) ($state['version_path'] ?? ''))) {
                    $stats['skipped']++;
                    continue;
                }
                $result = $this->store->save($item, $markdown);
                $stats[$result['changed'] ? 'regenerated' : 'skipped']++;
            } catch (\Throwable $error) {
                $stats['errors']++;
                $this->repository->saveState($item->key(), ['last_error' => $error->getMessage()]);
            }
        }
        $stats['next_offset'] = $offset + $stats['processed'];
        $stats['finished'] = $stats['processed'] < $limit || $stats['next_offset'] >= $this->registry->count();

        // Sweeping obsolete files only makes sense once the whole catalogue has
        // been walked; mid-batch we have no idea what still belongs.
        if ($stats['finished']) {
            $stats['pruned'] = $this->prune()['deleted'];
        }
        $this->repository->addEvent('regeneration', ['trigger' => $trigger, 'force' => $force, 'stats' => $stats], $stats['errors'] ? 'warning' : 'info');

        if ($stats['errors'] && !empty($this->options['notify_email']) && filter_var($this->options['notify_email_to'] ?? '', FILTER_VALIDATE_EMAIL)) {
            Factory::getMailer()->sendMail((string) Factory::getApplication()->getConfig()->get('mailfrom'), (string) Factory::getApplication()->getConfig()->get('fromname'), (string) $this->options['notify_email_to'], Lang::text('PLG_SYSTEM_AIFRIENDLY_REGEN_ERROR_SUBJECT', 'AI Friendly: regeneration errors'), json_encode($stats, JSON_PRETTY_PRINT));
        }

        return $stats;
    }

    /**
     * Removes static Markdown files that no longer correspond to exposed
     * content: deleted, renamed, or newly excluded by the rules.
     *
     * Refuses to run when the catalogue is larger than the scan limit. Pruning
     * from a partial view would delete the files of every item not seen, which
     * on a large site means most of them.
     *
     * @return array{deleted:int, kept:int, skipped:bool, reason?:string}
     */
    public function prune(int $limit = 5000): array
    {
        $total = $this->registry->count();

        if ($total > $limit) {
            return ['deleted' => 0, 'kept' => 0, 'skipped' => true, 'reason' => 'catalogue larger than the scan limit'];
        }

        $keep = [];
        $stale = [];
        $seen = 0;

        foreach ($this->registry->all([], max(1, $limit)) as $item) {
            $seen++;
            if ($this->rules->allows($item, 'regenerate')) {
                $keep[] = $item->staticFilename();
            } else {
                $stale[$item->staticFilename()] = $item->key();
            }
        }

        // A provider failing mid-walk would look like "nothing belongs here".
        if ($seen + $this->registry->skippedRows() < $total) {
            return ['deleted' => 0, 'kept' => 0, 'skipped' => true, 'reason' => 'incomplete scan of the catalogue'];
        }

        $result = $this->store->prune($keep);

        // Forget the checksum of what we deleted, so a later run rewrites the
        // file instead of assuming it is still on disk. The row itself stays:
        // it also holds the manual exclusion flag.
        foreach ($result['files'] as $file) {
            if (isset($stale[$file])) {
                $this->repository->saveState($stale[$file], ['checksum' => '', 'version_path' => '', 'last_generated' => null]);
            }
        }

        if ($result['deleted'] > 0) {
            $this->repository->addEvent('static_versions_prune', ['deleted' => $result['deleted'], 'files' => array_slice($result['files'], 0, 50)]);
        }

        return ['deleted' => $result['deleted'], 'kept' => $result['kept'], 'skipped' => false];
    }
}

