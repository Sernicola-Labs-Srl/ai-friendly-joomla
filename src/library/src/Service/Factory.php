<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory as CmsFactory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;
use Sernicola\Library\AiFriendly\Contract\ContentProviderInterface;
use Sernicola\Library\AiFriendly\Contract\RuleInterface;
use Sernicola\Library\AiFriendly\Provider\JoomlaContactProvider;
use Sernicola\Library\AiFriendly\Provider\JoomlaContentProvider;
use Sernicola\Library\AiFriendly\Provider\JoomlaTagProvider;
use Sernicola\Library\AiFriendly\Provider\YoothemeProvider;
use Sernicola\Library\AiFriendly\Rule\RuleEngine;
use Sernicola\Library\AiFriendly\Storage\Repository;
use Sernicola\Library\AiFriendly\Storage\StaticVersionStore;
use Sernicola\Library\AiFriendly\Support\EventNames;
use Sernicola\Library\AiFriendly\Support\Options;

final class Factory
{
    /** Plugin group third parties install into to contribute providers. */
    public const PLUGIN_GROUP = 'aifriendly';

    /** @var ContentProviderInterface[]|null Resolved once per request. */
    private static ?array $providers = null;

    /** @var RuleInterface[]|null Resolved once per request. */
    private static ?array $customRules = null;

    public static function repository(): Repository
    {
        return new Repository();
    }

    public static function registry(): ContentRegistry
    {
        return new ContentRegistry(self::providers());
    }

    /**
     * The built-in providers plus anything contributed by plugins of the
     * `aifriendly` group through onAiFriendlyCollectProviders.
     *
     * Memoised because the registry is rebuilt several times per request and
     * dispatching the event each time would be wasted work.
     *
     * @return ContentProviderInterface[]
     */
    public static function providers(): array
    {
        if (self::$providers !== null) {
            return self::$providers;
        }

        $providers = [new JoomlaContentProvider(), new JoomlaContactProvider(), new JoomlaTagProvider(), new YoothemeProvider()];

        try {
            PluginHelper::importPlugin(self::PLUGIN_GROUP);
            $event = new Event(EventNames::COLLECT_PROVIDERS, ['providers' => $providers]);
            CmsFactory::getApplication()->getDispatcher()->dispatch(EventNames::COLLECT_PROVIDERS, $event);

            $collected = $event->getArgument('providers', $providers);

            // An empty array is a legitimate choice and is honoured; anything
            // that is not an array at all is a programming error, and silently
            // accepting it would leave the site with no providers whatsoever.
            if (is_array($collected)) {
                $providers = $collected;
            } else {
                self::report('a listener replaced the provider list with a ' . gettype($collected) . '; ignored');
            }
        } catch (\Throwable $error) {
            // A faulty third-party plugin must never take down public output:
            // fall back to the built-in providers.
            self::report('provider collection failed: ' . $error->getMessage());
            $providers = [new JoomlaContentProvider(), new JoomlaContactProvider(), new JoomlaTagProvider(), new YoothemeProvider()];
        }

        return self::$providers = self::accept($providers);
    }

    /**
     * Clears the memoised provider list. Only needed by long-running processes
     * and tests; a normal request resolves the list once.
     */
    public static function resetProviders(): void
    {
        self::$providers = null;
    }

    public static function rules(?Repository $repository = null): RuleEngine
    {
        return new RuleEngine(Options::get(), $repository ?: self::repository(), self::customRules());
    }

    /**
     * Extra rules contributed by plugins of the `aifriendly` group through
     * onAiFriendlyCollectRules. Memoised for the same reason as the providers.
     *
     * @return RuleInterface[]
     */
    public static function customRules(): array
    {
        if (self::$customRules !== null) {
            return self::$customRules;
        }

        $rules = [];

        try {
            PluginHelper::importPlugin(self::PLUGIN_GROUP);
            $event = new Event(EventNames::COLLECT_RULES, ['rules' => $rules]);
            CmsFactory::getApplication()->getDispatcher()->dispatch(EventNames::COLLECT_RULES, $event);
            $collected = $event->getArgument('rules', $rules);

            if (is_array($collected)) {
                $rules = $collected;
            } else {
                self::report('a listener replaced the rule list with a ' . gettype($collected) . '; ignored');
            }
        } catch (\Throwable $error) {
            self::report('rule collection failed: ' . $error->getMessage());
            $rules = [];
        }

        $accepted = [];

        foreach ($rules as $rule) {
            if ($rule instanceof RuleInterface) {
                $accepted[] = $rule;
                continue;
            }

            self::report('rejected a rule that does not implement RuleInterface');
        }

        return self::$customRules = $accepted;
    }

    /**
     * Clears the memoised rule list. See resetProviders().
     */
    public static function resetRules(): void
    {
        self::$customRules = null;
    }

    public static function staticStore(?Repository $repository = null): StaticVersionStore
    {
        return new StaticVersionStore($repository ?: self::repository());
    }

    /**
     * Keeps only real providers, and only the first of each name.
     *
     * ContentRegistry resolves a content key back to its provider by name, so a
     * duplicate name would silently shadow the provider registered before it.
     *
     * @param array<mixed> $providers
     * @return ContentProviderInterface[]
     */
    private static function accept(array $providers): array
    {
        $accepted = [];
        $seen = [];

        foreach ($providers as $provider) {
            if (!$provider instanceof ContentProviderInterface) {
                self::report('rejected a provider that does not implement ContentProviderInterface');
                continue;
            }

            try {
                $name = trim($provider->name());
            } catch (\Throwable $error) {
                self::report('rejected a provider whose name() failed: ' . $error->getMessage());
                continue;
            }

            if ($name === '') {
                self::report('rejected a provider with an empty name');
                continue;
            }

            if (isset($seen[$name])) {
                self::report('rejected a duplicate provider name: ' . $name);
                continue;
            }

            $seen[$name] = true;
            $accepted[] = $provider;
        }

        return $accepted;
    }

    /**
     * Announces a rejected or failing provider so a site can log it, without
     * writing to the database on every public request.
     */
    private static function report(string $message): void
    {
        try {
            $event = new Event(EventNames::RUNTIME_ERROR, ['message' => $message, 'context' => 'providers']);
            CmsFactory::getApplication()->getDispatcher()->dispatch(EventNames::RUNTIME_ERROR, $event);
        } catch (\Throwable) {
            // Reporting a problem must not become the problem.
        }
    }
}
