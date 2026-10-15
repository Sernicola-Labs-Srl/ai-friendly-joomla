<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Storage;

defined('_JEXEC') or die;

use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Sernicola\Library\AiFriendly\Dto\ContentItem;

final class StaticVersionStore
{
    private string $directory;

    public function __construct(private readonly Repository $repository, ?string $directory = null)
    {
        $this->directory = $directory ?: JPATH_ROOT . '/media/com_aifriendly/versions';
    }

    public function save(ContentItem $item, string $markdown): array
    {
        Folder::create($this->directory);
        $path = $this->directory . '/' . $item->staticFilename();
        $checksum = hash('sha256', $markdown);
        $state = $this->repository->getState($item->key());
        $changed = ($state['checksum'] ?? '') !== $checksum || !is_file($path);
        if ($changed && !File::write($path, $markdown)) {
            throw new \RuntimeException('Unable to save the static Markdown file.');
        }
        $this->repository->saveState($item->key(), [
            'checksum' => $checksum,
            'version_path' => $path,
            'last_generated' => date('Y-m-d H:i:s'),
            'last_error' => '',
        ]);

        return ['saved' => true, 'changed' => $changed, 'path' => $path, 'checksum' => $checksum];
    }

    public function get(ContentItem $item): ?string
    {
        $state = $this->repository->getState($item->key());
        $path = (string) ($state['version_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $real = realpath($path);
        $root = realpath($this->directory);
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        // Joomla\Filesystem\File has no read(): that method belonged to the
        // former CMS class and did not survive the move to the framework package.
        $content = file_get_contents($real);

        return is_string($content) && trim($content, "\xEF\xBB\xBF\x00..\x20") !== '' ? $content : null;
    }

    public function clear(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }
        $count = 0;
        foreach (Folder::files($this->directory, '\\.md$', false, true) as $file) {
            if (File::delete($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Deletes every Markdown file whose name is not in $keep.
     *
     * Callers must pass the filenames of the COMPLETE set of items that should
     * exist. Handing over a partial list would delete everything else, which is
     * why Regenerator refuses to prune from a truncated scan.
     *
     * @param array<int, string> $keep Filenames, not paths.
     * @return array{deleted:int, kept:int, files:array<int, string>}
     */
    public function prune(array $keep): array
    {
        if (!is_dir($this->directory)) {
            return ['deleted' => 0, 'kept' => 0, 'files' => []];
        }

        $keep = array_flip($keep);
        $deleted = [];
        $kept = 0;

        foreach (Folder::files($this->directory, '\\.md$', false, true) as $file) {
            if (isset($keep[basename($file)])) {
                $kept++;
                continue;
            }

            if (File::delete($file)) {
                $deleted[] = basename($file);
            }
        }

        return ['deleted' => count($deleted), 'kept' => $kept, 'files' => $deleted];
    }

    public function stats(): array
    {
        $files = is_dir($this->directory) ? Folder::files($this->directory, '\\.md$', false, true) : [];

        return ['directory' => $this->directory, 'writable' => is_dir($this->directory) ? is_writable($this->directory) : is_writable(dirname($this->directory)), 'count' => count($files)];
    }
}
