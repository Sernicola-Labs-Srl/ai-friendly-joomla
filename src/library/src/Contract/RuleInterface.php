<?php

namespace Sernicola\Library\AiFriendly\Contract;

defined('_JEXEC') or die;

use Sernicola\Library\AiFriendly\Dto\ContentItem;

interface RuleInterface
{
    public function allows(ContentItem $item, array $options): bool;

    public function reason(): string;
}

