<?php

namespace Sernicola\Library\AiFriendly\Service;

defined('_JEXEC') or die;

use Sernicola\Library\AiFriendly\Dto\ContentItem;

/** Publishes a complete corpus without replacing a file owned by somebody else. */
final class FullCorpus
{
    public static function target(): string
    {
        return JPATH_ROOT . '/llms-full.txt';
    }

    public static function begin(string $id): string
    {
        $target = self::target();
        if (!is_writable(dirname($target))) {
            throw new \RuntimeException('The Joomla document root is not writable for llms-full.txt.');
        }
        $temporary = dirname($target) . '/.aifriendly-llms-full-' . preg_replace('/[^a-zA-Z0-9-]/', '', $id) . '.tmp';
        if (file_put_contents($temporary, '', LOCK_EX) === false) {
            throw new \RuntimeException('Could not create the full corpus temporary file.');
        }
        return $temporary;
    }

    public static function append(string $temporary, ContentItem $item, string $markdown): void
    {
        $entry = "\n\n---\n\nSource: " . html_entity_decode($item->canonicalUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') . "\n\n" . trim($markdown) . "\n";
        if (file_put_contents($temporary, $entry, FILE_APPEND | LOCK_EX) !== strlen($entry)) {
            throw new \RuntimeException('Could not append to the full corpus.');
        }
    }

    /** Discard an append interrupted before its cursor was stored. */
    public static function restore(string $temporary, int $committedBytes): void
    {
        $stream = @fopen($temporary, 'c+b');
        if (!$stream) throw new \RuntimeException('The full corpus temporary file is unavailable.');
        try {
            if (!flock($stream, LOCK_EX)) throw new \RuntimeException('Could not lock the full corpus temporary file.');
            $size = fstat($stream)['size'] ?? -1;
            if ($committedBytes < 0 || $size < $committedBytes || !ftruncate($stream, $committedBytes)) {
                throw new \RuntimeException('Could not restore the full corpus to its committed position.');
            }
            fflush($stream);
            flock($stream, LOCK_UN);
        } finally {
            fclose($stream);
        }
    }

    /** @return string SHA-256 of the published file. */
    public static function publish(string $temporary, string $knownHash): string
    {
        $target = self::target();
        if (is_file($target)) {
            $current = hash_file('sha256', $target);
            if ($knownHash === '' || !is_string($current) || !hash_equals($knownHash, $current)) {
                throw new \RuntimeException('An external or modified llms-full.txt already exists.');
            }
        }
        $hash = hash_file('sha256', $temporary);
        if (!is_string($hash) || !rename($temporary, $target)) {
            throw new \RuntimeException('Could not publish llms-full.txt atomically.');
        }
        return $hash;
    }

    public static function removeOwned(string $knownHash): bool
    {
        $target = self::target();
        if ($knownHash === '' || !is_file($target)) return false;
        $current = hash_file('sha256', $target);
        return is_string($current) && hash_equals($knownHash, $current) && unlink($target);
    }

    public static function state(bool $enabled, string $knownHash): array
    {
        $target = self::target();
        $hash = is_file($target) ? hash_file('sha256', $target) : false;
        return [
            'enabled' => $enabled,
            'ready' => $enabled && is_string($hash) && $knownHash !== '' && hash_equals($knownHash, $hash),
            'size' => is_file($target) ? (int) filesize($target) : 0,
            'url' => '/llms-full.txt',
        ];
    }
}
