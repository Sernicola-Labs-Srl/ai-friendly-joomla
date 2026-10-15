<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Storage;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Sernicola\Library\AiFriendly\Support\Lang;

final class Repository
{
    private $db;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
    }

    public function isExcluded(string $contentKey): bool
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('excluded'))
            ->from($this->db->quoteName('#__aifriendly_content_state'))
            ->where($this->db->quoteName('content_key') . ' = ' . $this->db->quote($contentKey));

        return (bool) $this->db->setQuery($query)->loadResult();
    }

    public function toggleExcluded(string $contentKey): bool
    {
        $excluded = !$this->isExcluded($contentKey);
        $this->saveState($contentKey, ['excluded' => $excluded ? 1 : 0]);

        return $excluded;
    }

    public function saveState(string $contentKey, array $values): void
    {
        $existing = $this->getState($contentKey);
        $row = (object) array_merge([
            'content_key' => $contentKey,
            'excluded' => 0,
            'checksum' => '',
            'version_path' => '',
            'last_generated' => null,
            'last_error' => '',
        ], $existing ?: [], $values);

        if ($existing) {
            $this->db->updateObject('#__aifriendly_content_state', $row, 'content_key');
        } else {
            $this->db->insertObject('#__aifriendly_content_state', $row);
        }
    }

    public function getState(string $contentKey): array
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__aifriendly_content_state'))
            ->where($this->db->quoteName('content_key') . ' = ' . $this->db->quote($contentKey));
        $row = $this->db->setQuery($query)->loadAssoc();

        return is_array($row) ? $row : [];
    }

    public function addEvent(string $type, array $payload = [], string $level = 'info'): void
    {
        $row = (object) [
            'event_type' => substr($type, 0, 80),
            'level' => substr($level, 0, 20),
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created' => Factory::getDate()->toSql(),
        ];
        $this->db->insertObject('#__aifriendly_events', $row);
        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__aifriendly_events'))
            ->where($this->db->quoteName('id') . ' NOT IN (SELECT id FROM (SELECT id FROM ' . $this->db->quoteName('#__aifriendly_events') . ' ORDER BY id DESC LIMIT 200) recent)');
        try {
            $this->db->setQuery($query)->execute();
        } catch (\Throwable) {
            // Retention failure must never break public output.
        }
    }

    public function events(int $limit = 30): array
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__aifriendly_events'))
            ->order($this->db->quoteName('id') . ' DESC');
        $rows = $this->db->setQuery($query, 0, max(1, min(200, $limit)))->loadAssocList() ?: [];
        foreach ($rows as &$row) {
            $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
        }

        return $rows;
    }

    public function createSnapshot(string $content, string $reason = 'manual'): array
    {
        $row = (object) [
            'reason' => substr($reason, 0, 80),
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'line_count' => substr_count($content, "\n") + 1,
            'token_estimate' => (int) ceil(mb_strlen($content) / 4),
            'created' => Factory::getDate()->toSql(),
        ];
        $this->db->insertObject('#__aifriendly_snapshots', $row, 'id');
        $this->addEvent('llms_snapshot_create', ['id' => (int) $row->id, 'reason' => $reason]);

        return (array) $row;
    }

    public function snapshots(int $limit = 30): array
    {
        $query = $this->db->getQuery(true)
            ->select([$this->db->quoteName('id'), $this->db->quoteName('reason'), $this->db->quoteName('content_hash'), $this->db->quoteName('line_count'), $this->db->quoteName('token_estimate'), $this->db->quoteName('created')])
            ->from($this->db->quoteName('#__aifriendly_snapshots'))
            ->order($this->db->quoteName('id') . ' DESC');

        return $this->db->setQuery($query, 0, max(1, min(100, $limit)))->loadAssocList() ?: [];
    }

    public function snapshot(int $id): ?array
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__aifriendly_snapshots'))
            ->where($this->db->quoteName('id') . ' = ' . $id);
        $row = $this->db->setQuery($query)->loadAssoc();

        return $row ?: null;
    }

    public function diffSnapshots(int $leftId, int $rightId): array
    {
        $left = $this->snapshot($leftId);
        $right = $this->snapshot($rightId);
        if (!$left || !$right) {
            throw new \RuntimeException(Lang::text('COM_AIFRIENDLY_ERR_SNAPSHOT_MISSING', 'The snapshot is no longer available.'));
        }
        $leftLines = preg_split('/\R/u', (string) $left['content']) ?: [];
        $rightLines = preg_split('/\R/u', (string) $right['content']) ?: [];
        $count = max(count($leftLines), count($rightLines));
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $a = $leftLines[$i] ?? '';
            $b = $rightLines[$i] ?? '';
            $rows[] = ['line' => $i + 1, 'left' => $a, 'right' => $b, 'changed' => $a !== $b];
        }

        return ['left' => $left, 'right' => $right, 'rows' => $rows];
    }
}

