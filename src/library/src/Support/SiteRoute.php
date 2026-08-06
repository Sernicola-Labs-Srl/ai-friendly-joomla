<?php

namespace Sernicola\Library\AiFriendly\Support;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

/**
 * Says what a public path points at.
 *
 * Matching a path against content aliases is not enough: a SEF URL is built
 * from the menu tree, so `/corsi/corso-di-canto-milano.html` can be a menu item
 * whose alias has nothing to do with the article's own.
 *
 * The answer is taken from Joomla itself. The system plugin strips the `.md`
 * suffix from the request URI before the application routes, so by the time a
 * provider asks, Joomla has already resolved the real page through its own
 * router — menu tree, SEF suffix, language filter and third-party routers all
 * included. Re-running the router by hand would duplicate that work and its
 * side effects, and would be a second implementation to keep correct.
 */
final class SiteRoute
{
    /**
     * Query variables for $path, or an empty array when Joomla did not route it.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $path): array
    {
        try {
            $app = Factory::getApplication();

            if (!$app->isClient('site') || !self::isCurrent($path)) {
                return [];
            }

            $input = $app->getInput();
            $vars = [];

            foreach (['option', 'view'] as $key) {
                if (($value = (string) $input->getCmd($key, '')) !== '') {
                    $vars[$key] = $value;
                }
            }

            $id = $input->get('id', null, 'raw');

            if ($id !== null && $id !== '') {
                $vars['id'] = $id;
            }

            return $vars + self::activeMenuQuery($app);
        } catch (\Throwable) {
            // Asked before routing, or an application without a menu: the
            // caller falls back to matching by alias.
            return [];
        }
    }

    /**
     * The id $path resolves to for the given component and view, or null.
     */
    public static function itemId(string $path, string $option, string $view): ?string
    {
        $vars = self::parse($path);

        if (($vars['option'] ?? '') !== $option || ($vars['view'] ?? '') !== $view) {
            return null;
        }

        $id = $vars['id'] ?? null;

        // com_tags addresses tags as id[0].
        if (is_array($id)) {
            $id = $id[0] ?? null;
        }

        $id = is_scalar($id) ? trim((string) $id) : '';

        // Joomla carries the id as "105-alias" in a URL and "105:alias" in a
        // query, depending on the routing mode.
        $id = (string) preg_replace('/[-:].*$/', '', $id);

        return $id !== '' && ctype_digit($id) ? $id : null;
    }

    /**
     * Whether $path is the path the application is currently serving.
     *
     * Guards against a provider resolving some unrelated path — a link being
     * rewritten, say — against the variables of the current request.
     */
    private static function isCurrent(string $path): bool
    {
        $current = Path::normalize(Uri::getInstance()->getPath());
        $base = Path::normalize(Uri::base(true));

        if ($base !== '/' && str_starts_with($current, $base)) {
            $current = Path::normalize(substr($current, strlen($base)) ?: '/');
        }

        return $current === Path::normalize($path);
    }

    /**
     * The active menu item's query, used only for variables the request itself
     * left unset.
     *
     * @return array<string, mixed>
     */
    private static function activeMenuQuery($app): array
    {
        if (!method_exists($app, 'getMenu')) {
            return [];
        }

        $active = $app->getMenu()->getActive();

        return $active && !empty($active->query) && is_array($active->query) ? $active->query : [];
    }
}
