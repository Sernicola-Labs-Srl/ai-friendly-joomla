<?php

/**
 * Test double for forSEF, used only by the integration checks of AI Friendly.
 *
 * Like the real component it takes over routing: an address recorded as a main
 * row of #__forsef_urls is routed to its non-SEF query, and the technical
 * address Joomla would build (`/section/123-alias.html`) is a 404.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Exception\RouteNotFoundException;
use Joomla\CMS\Router\SiteRouter;

class PlgSystemFakeforsef extends CMSPlugin
{
    public function onAfterInitialise(): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $app->getRouter()->attachParseRule(function ($router, $uri) {
            $path = trim(rawurldecode($uri->getPath()), '/');

            if ($path === '' || str_starts_with($path, 'index.php')) {
                return;
            }

            // The router has already removed the SEF suffix; the table keeps it.
            $path .= '.html';

            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName(['nonsef', 'duplicate']))
                ->from($db->quoteName('#__forsef_urls'))
                ->where($db->quoteName('sef') . ' = ' . $db->quote($path));
            $row = $db->setQuery($query)->loadObject();

            if ($row && !(int) $row->duplicate) {
                $uri->setPath('');
                $uri->setQuery(substr($row->nonsef, strpos($row->nonsef, '?') + 1));

                return;
            }

            if ($row || preg_match('#/[0-9]+-[^/]+$#', $path)) {
                throw new RouteNotFoundException('Not Found', 404);
            }
        }, SiteRouter::PROCESS_BEFORE);
    }
}
