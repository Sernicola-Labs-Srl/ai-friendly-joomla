<?php

namespace Sernicola\Library\AiFriendly\Support;

defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;

/** CLI Scheduler has no current identity; use the guest's public levels. */
final class ViewLevels
{
    public static function current(): array
    {
        $identity = Factory::getApplication()->getIdentity();
        return $identity ? $identity->getAuthorisedViewLevels() : Access::getAuthorisedViewLevels(0);
    }
}
