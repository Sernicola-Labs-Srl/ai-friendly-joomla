<?php

namespace Sernicola\Library\AiFriendly\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Sernicola\Library\AiFriendly\Markdown\Generator;
use Sernicola\Library\AiFriendly\Rule\RuleEngine;
use Sernicola\Library\AiFriendly\Storage\Repository;
use Sernicola\Library\AiFriendly\Storage\StaticVersionStore;
use Sernicola\Library\AiFriendly\Support\Options;
use Sernicola\Library\AiFriendly\Service\Factory as AiFactory;

/** One bounded step per request; Joomla Scheduler or the Hub can resume it. */
final class RegenerationJob
{
    private $db;

    public function __construct(
        private readonly ContentRegistry $registry,
        private readonly RuleEngine $rules,
        private readonly StaticVersionStore $store,
        private readonly Repository $repository,
    ) {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
    }

    public static function create(): self
    {
        $repository = AiFactory::repository();
        return new self(AiFactory::registry(), AiFactory::rules($repository), AiFactory::staticStore($repository), $repository);
    }

    public function status(): array
    {
        $query = $this->db->getQuery(true)->select('*')->from($this->db->quoteName('#__aifriendly_jobs'))
            ->order($this->db->quoteName('started') . ' DESC')->order($this->db->quoteName('id') . ' DESC');
        return $this->public($this->db->setQuery($query, 0, 1)->loadAssoc() ?: []);
    }

    public function start(bool $force = false, string $trigger = 'manual', ?array $keys = null): array
    {
        if (!$this->lock()) return $this->status();
        try {
            $current = $this->status();
            if (($current['status'] ?? '') === 'running') return $current;
            if (($current['status'] ?? '') === 'indexing') {
                $stale = $this->latest();
                if (time() - strtotime((string) $stale['started']) < 300) return $current;
                $stale['status'] = 'failed';
                $stale['error'] = 'Interrupted while indexing the content catalogue.';
                $stale['finished'] = Factory::getDate()->toSql();
                $staleRow = (object) $stale;
                $this->db->updateObject('#__aifriendly_jobs', $staleRow, 'id');
            }
            $id = $this->uuid();
            $stats = $this->emptyStats();
            $row = (object) [
                'id' => $id, 'status' => 'indexing', 'trigger_name' => substr($trigger, 0, 40),
                'force_run' => $force ? 1 : 0, 'total' => 0, 'cursor' => 0, 'dirty' => 0,
                'stats' => json_encode($stats), 'error' => '', 'corpus_path' => '',
                'started' => Factory::getDate()->toSql(), 'finished' => null,
            ];
            $this->db->insertObject('#__aifriendly_jobs', $row);
            try {
                $queryStarted = microtime(true);
                $seq = 0;
                if ($keys !== null) {
                    foreach (array_unique($keys) as $key) {
                        if (strlen($key) > 255) throw new \RuntimeException('Content key exceeds 255 characters.');
                        $entry = (object) ['job_id' => $id, 'seq' => ++$seq, 'content_key' => $key];
                        $this->db->insertObject('#__aifriendly_job_items', $entry);
                    }
                } else {
                    $catalogueSize = $this->registry->count();
                    for ($offset = 0; $offset < $catalogueSize; $offset += 250) {
                        foreach ($this->registry->all([], 250, $offset) as $item) {
                            if (strlen($item->key()) > 255) throw new \RuntimeException('Content key exceeds 255 characters.');
                            $entry = (object) [
                                'job_id' => $id, 'seq' => ++$seq, 'content_key' => $item->key(),
                            ];
                            $this->db->insertObject('#__aifriendly_job_items', $entry);
                        }
                    }
                }
                $stats['query_seconds'] = round(microtime(true) - $queryStarted, 4);
                $row->total = $seq;
                $options = Options::get();
                if ($keys === null && !empty($options['llms_full_enabled'])) $row->corpus_path = FullCorpus::begin($id);
                $row->status = 'running';
                $row->stats = json_encode($stats);
                $this->db->updateObject('#__aifriendly_jobs', $row, 'id');
                return $this->public((array) $row);
            } catch (\Throwable $error) {
                $row->status = 'failed';
                $row->error = $error->getMessage();
                $row->finished = Factory::getDate()->toSql();
                $this->db->updateObject('#__aifriendly_jobs', $row, 'id');
                if ($row->corpus_path !== '' && is_file($row->corpus_path)) unlink($row->corpus_path);
                return $this->public((array) $row);
            }
        } finally {
            $this->unlock();
        }
    }

    public function step(): array
    {
        if (!$this->lock()) return $this->status();
        $job = [];
        try {
            $job = $this->latest();
            if (!$job || $job['status'] !== 'running') return $this->public($job);
            $options = Options::get();
            $stats = json_decode((string) $job['stats'], true) ?: $this->emptyStats();
            if ($job['corpus_path'] !== '') FullCorpus::restore($job['corpus_path'], (int) ($stats['corpus_bytes'] ?? 0));
            $limit = min(25, max(1, (int) ($options['regenerate_batch_size'] ?? 100)));
            $queryStarted = microtime(true);
            $query = $this->db->getQuery(true)->select(['seq', 'content_key'])
                ->from($this->db->quoteName('#__aifriendly_job_items'))
                ->where($this->db->quoteName('job_id') . ' = ' . $this->db->quote($job['id']))
                ->where($this->db->quoteName('seq') . ' > ' . (int) $job['cursor'])
                ->order($this->db->quoteName('seq') . ' ASC');
            $rows = $this->db->setQuery($query, 0, $limit)->loadAssocList() ?: [];
            $stats['query_seconds'] += microtime(true) - $queryStarted;
            $generator = new Generator($options);
            $guestLevels = Access::getAuthorisedViewLevels(0);
            foreach ($rows as $row) {
                $job['cursor'] = (int) $row['seq'];
                $stats['processed']++;
                $itemStarted = microtime(true);
                try {
                    $item = $this->registry->findByKey((string) $row['content_key']);
                    if (!$item || !$this->rules->allows($item, 'regenerate')) {
                        $stats['excluded']++;
                        continue;
                    }
                    $markdown = $generator->generate($item);
                    if (!empty($options['static_md_files'])) {
                        $saved = $this->store->save($item, $markdown);
                        $stats[$saved['changed'] ? 'regenerated' : 'skipped']++;
                    } else {
                        $stats['regenerated']++;
                    }
                    $access = $item->metadata['access_level'] ?? null;
                    $public = $access === null ? $item->accessible : in_array((int) $access, $guestLevels, true);
                    if ($job['corpus_path'] !== '' && $public && $this->rules->allows($item, 'serve')) {
                        FullCorpus::append($job['corpus_path'], $item, $markdown);
                    }
                } catch (\Throwable $error) {
                    $stats['errors']++;
                    try {
                        $this->repository->saveState((string) $row['content_key'], ['last_error' => $error->getMessage()]);
                    } catch (\Throwable) {
                        // Keep the original failure and continue the bounded batch.
                    }
                } finally {
                    $stats['generation_seconds'] += microtime(true) - $itemStarted;
                    if ($job['corpus_path'] !== '') {
                        clearstatcache(true, $job['corpus_path']);
                        $size = filesize($job['corpus_path']);
                        if ($size === false) throw new \RuntimeException('Could not measure the full corpus temporary file.');
                        $stats['corpus_bytes'] = $size;
                    }
                    $job['stats'] = json_encode($this->rounded($stats));
                    $jobRow = (object) $job;
                    $this->db->updateObject('#__aifriendly_jobs', $jobRow, 'id');
                }
            }
            if (!$rows || (int) $job['cursor'] >= (int) $job['total']) {
                $this->finish($job, $stats, $options);
            } else {
                $job['stats'] = json_encode($this->rounded($stats));
                $jobRow = (object) $job;
                $this->db->updateObject('#__aifriendly_jobs', $jobRow, 'id');
            }
            return $this->public($job);
        } catch (\Throwable $error) {
            if ($job) {
                $job['status'] = 'failed';
                $job['error'] = $error->getMessage();
                $job['finished'] = Factory::getDate()->toSql();
                $jobRow = (object) $job;
                $this->db->updateObject('#__aifriendly_jobs', $jobRow, 'id');
                if ($job['corpus_path'] !== '' && is_file($job['corpus_path'])) unlink($job['corpus_path']);
                return $this->public($job);
            }
            throw $error;
        } finally {
            $this->unlock();
        }
    }

    public function markDirty(): void
    {
        $job = $this->latest();
        if ($job && $job['status'] === 'running') {
            $job['dirty'] = 1;
            $jobRow = (object) $job;
            $this->db->updateObject('#__aifriendly_jobs', $jobRow, 'id');
        }
        if (!empty(Options::get()['llms_full_enabled'])) Options::save(['llms_full_dirty' => 1]);
    }

    public function startAuthor(int $userId): array
    {
        $query = $this->db->getQuery(true)->select(['id', 'language'])
            ->from($this->db->quoteName('#__content'))
            ->where($this->db->quoteName('created_by') . ' = ' . $userId)
            ->where($this->db->quoteName('state') . ' = 1')
            ->order($this->db->quoteName('id') . ' ASC');
        $keys = [];
        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $keys[] = 'joomla:article:' . $row['id'] . ':' . ($row['language'] ?: '*');
        }
        return $this->start(false, 'author:' . $userId, $keys);
    }

    private function finish(array &$job, array &$stats, array $options): void
    {
        $started = microtime(true);
        if (!str_starts_with((string) $job['trigger_name'], 'author:') && !empty($options['static_md_files']) && $stats['errors'] === 0) {
            $pruned = (new Regenerator($this->registry, $this->rules, $this->store, $this->repository, $options))->prune(max(5000, $this->registry->count()));
            if (!empty($pruned['skipped'])) {
                $stats['errors']++;
                $job['error'] = 'Cleanup could not verify the complete catalogue.';
            } else {
                $stats['deleted_obsolete'] = $pruned['deleted'];
            }
        }
        $stats['cleanup_seconds'] += microtime(true) - $started;
        $started = microtime(true);
        (new LlmsBuilder($this->registry, $this->rules, $options))->build();
        $stats['index_seconds'] += microtime(true) - $started;
        if ($stats['errors'] === 0 && $job['corpus_path'] !== '' && !empty($options['llms_full_enabled'])) {
            try {
                $hash = FullCorpus::publish($job['corpus_path'], (string) ($options['llms_full_hash'] ?? ''));
                Options::save(['llms_full_hash' => $hash]);
            } catch (\Throwable $error) {
                $stats['errors']++;
                $job['error'] = $error->getMessage();
            }
        }
        if ($job['corpus_path'] !== '' && is_file($job['corpus_path'])) unlink($job['corpus_path']);
        $job['status'] = $stats['errors'] ? 'failed' : 'complete';
        $job['finished'] = Factory::getDate()->toSql();
        $job['stats'] = json_encode($this->rounded($stats));
        $jobRow = (object) $job;
        $this->db->updateObject('#__aifriendly_jobs', $jobRow, 'id');
        $query = $this->db->getQuery(true)->delete($this->db->quoteName('#__aifriendly_job_items'))
            ->where($this->db->quoteName('job_id') . ' = ' . $this->db->quote($job['id']));
        $this->db->setQuery($query)->execute();
        $this->repository->addEvent('regeneration', ['job' => $job['id'], 'stats' => $this->rounded($stats)], $stats['errors'] ? 'warning' : 'info');
        if (empty($job['dirty'])) Options::save(['llms_full_dirty' => 0]);
    }

    private function latest(): array
    {
        $query = $this->db->getQuery(true)->select('*')->from($this->db->quoteName('#__aifriendly_jobs'))
            ->order($this->db->quoteName('started') . ' DESC')->order($this->db->quoteName('id') . ' DESC');
        return $this->db->setQuery($query, 0, 1)->loadAssoc() ?: [];
    }

    private function public(array $job): array
    {
        if (!$job) return ['id' => '', 'status' => 'idle', 'total' => 0, 'cursor' => 0, 'stats' => $this->emptyStats()];
        return [
            'id' => $job['id'], 'status' => $job['status'], 'total' => (int) $job['total'],
            'cursor' => (int) $job['cursor'], 'stats' => is_array($job['stats']) ? $job['stats'] : (json_decode((string) $job['stats'], true) ?: $this->emptyStats()),
            'error' => $job['error'], 'dirty' => !empty($job['dirty']),
        ];
    }

    private function emptyStats(): array
    {
        return ['processed' => 0, 'regenerated' => 0, 'skipped' => 0, 'excluded' => 0, 'errors' => 0, 'corpus_bytes' => 0,
            'query_seconds' => 0.0, 'generation_seconds' => 0.0, 'cleanup_seconds' => 0.0, 'index_seconds' => 0.0];
    }

    private function rounded(array $stats): array
    {
        foreach (['query_seconds', 'generation_seconds', 'cleanup_seconds', 'index_seconds'] as $key) $stats[$key] = round((float) $stats[$key], 4);
        return $stats;
    }

    private function lock(): bool
    {
        return (int) $this->db->setQuery('SELECT GET_LOCK(' . $this->db->quote($this->lockName()) . ', 0)')->loadResult() === 1;
    }

    private function unlock(): void
    {
        $this->db->setQuery('SELECT RELEASE_LOCK(' . $this->db->quote($this->lockName()) . ')')->loadResult();
    }

    private function lockName(): string
    {
        return 'aifriendly_' . substr(hash('sha256', JPATH_ROOT), 0, 24);
    }

    private function uuid(): string
    {
        return sprintf('%016d', (int) (microtime(true) * 1000000)) . '-' . substr(bin2hex(random_bytes(10)), 0, 19);
    }
}
