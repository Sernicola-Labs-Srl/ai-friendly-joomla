<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Contract;

defined('_JEXEC') or die;

use Sernicola\Library\AiFriendly\Dto\ContentItem;

interface ContentProviderInterface
{
    public function name(): string;

    /** @return iterable<ContentItem> */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): iterable;

    public function resolve(string $path, ?string $language = null): ?ContentItem;

    public function find(string $id, ?string $language = null): ?ContentItem;

    public function count(array $filters = []): int;
}

