<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Contract;

defined('_JEXEC') or die;

use Sernicola\Library\AiFriendly\Dto\ContentItem;

interface RuleInterface
{
    public function allows(ContentItem $item, array $options): bool;

    public function reason(): string;
}

