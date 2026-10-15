<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Integration;

defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;
use Sernicola\Library\AiFriendly\Schema\OutputMode;
use Sernicola\Library\AiFriendly\Schema\SchemaBuilder;
use Sernicola\Library\AiFriendly\Support\Options;

/**
 * Merges the AI Friendly identity into the structured data 4SEO publishes.
 *
 * 4SEO hooks are not Joomla plugin events: they are registered from the weeblr
 * functions file, so `forseo-bridge.php` is included from there and calls into
 * this class. See the Hub, Schema section, for the exact snippet.
 *
 * The payload shape was established by probing a live 4SEO site, and it is not
 * what the documentation suggests. `forseo_structured_data` receives a map of
 * rule name to a COMPLETE JSON-LD document:
 *
 *     ['breadcrumb' => ['@context' => ..., '@type' => 'BreadcrumbList', ...],
 *      'article'    => ['@context' => ..., '@graph' => [ ...nodes... ]]]
 *
 * Every entry becomes its own script tag. Treating those documents as if they
 * were nodes is what an earlier version did, and it published our identity as a
 * stray extra block while stripping values from 4SEO's own documents.
 *
 * Two rules follow from that, and the tests enforce both:
 *
 *  - We enrich exactly one node inside one document, in place. Nothing is
 *    added at the top level, nothing is removed, no key is reindexed.
 *  - We never author. With no document holding an entity we recognise, 4SEO's
 *    payload is returned untouched.
 */
final class ForSeo
{
    public const BRIDGE_FILE = 'aifriendly/forseo-bridge.php';

    public static function functionsFile(): string
    {
        return JPATH_LIBRARIES . '/weeblr/forseo_functions.php';
    }

    /** Markers that fence the lines we add, so that they can be found and removed again. */
    public const BLOCK_BEGIN = '// BEGIN AI Friendly bridge';
    public const BLOCK_END = '// END AI Friendly bridge';

    /** Marks a functions file that AI Friendly created itself, so that it can delete it again. */
    public const CREATED_MARK = '// File created by AI Friendly';

    /**
     * The lines that register the bridge. The is_file() guard keeps 4SEO working
     * if the bridge file is ever missing, for instance after a manual cleanup.
     */
    public static function snippet(): string
    {
        return "if (is_file(JPATH_LIBRARIES . '/" . self::BRIDGE_FILE . "')) {\n"
            . "    include JPATH_LIBRARIES . '/" . self::BRIDGE_FILE . "';\n"
            . '}';
    }

    public static function bridgeRegistered(): bool
    {
        $file = self::functionsFile();

        if (!is_file($file) || !is_readable($file)) {
            return false;
        }

        return str_contains((string) file_get_contents($file), self::BRIDGE_FILE);
    }

    /**
     * Returns the functions file with the bridge added, or null when the file is
     * in a shape we do not dare to edit (a closing PHP tag at the end).
     */
    public static function addBridge(string $content): ?string
    {
        if (str_contains($content, self::BRIDGE_FILE)) {
            return $content;
        }

        if (trim($content) === '') {
            $content = "<?php\n\n" . self::CREATED_MARK . "; it is removed together with AI Friendly.\ndefined('_JEXEC') or die;\n";
        } elseif (str_ends_with(rtrim($content), '?>')) {
            return null;
        }

        return $content . "\n" . self::BLOCK_BEGIN . ": added from Components > AI Friendly > Schema.\n"
            . self::snippet() . "\n" . self::BLOCK_END . "\n";
    }

    /**
     * Returns the functions file without anything AI Friendly added, including
     * the single include line that earlier versions asked users to paste.
     */
    public static function removeBridge(string $content): string
    {
        $content = (string) preg_replace(
            '#\R?' . preg_quote(self::BLOCK_BEGIN, '#') . '.*?' . preg_quote(self::BLOCK_END, '#') . '\R?#s',
            '',
            $content
        );

        return (string) preg_replace(
            '#^[ \t]*include[ \t]+JPATH_LIBRARIES[ \t]*\.[ \t]*[\'"]/' . preg_quote(self::BRIDGE_FILE, '#') . '[\'"][ \t]*;[ \t]*\R?#m',
            '',
            $content
        );
    }

    /** True when nothing is left in a file that we created and then emptied. */
    public static function isOnlyOurs(string $original, string $cleaned): bool
    {
        if (!str_contains($original, self::CREATED_MARK)) {
            return false;
        }

        $code = (string) preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $cleaned);
        $code = (string) preg_replace('#<\?php|\?>|defined\(\s*[\'"]_JEXEC[\'"]\s*\)\s*or\s+die\s*;#i', '', $code);

        return trim($code) === '';
    }

    /**
     * Adds the bridge to the 4SEO functions file, with the user's explicit consent
     * given by the caller. Never creates anything outside libraries/weeblr.
     *
     * @return array{ok: bool, code: string}
     */
    public static function enableBridge(): array
    {
        $file = self::functionsFile();

        if (!is_dir(dirname($file))) {
            return ['ok' => false, 'code' => 'missing'];
        }

        $exists = is_file($file);
        $content = $exists ? file_get_contents($file) : '';

        if ($content === false || ($exists && !is_writable($file)) || (!$exists && !is_writable(dirname($file)))) {
            return ['ok' => false, 'code' => 'not_writable'];
        }

        $updated = self::addBridge($content);

        if ($updated === null) {
            return ['ok' => false, 'code' => 'format'];
        }

        if ($updated === $content) {
            return ['ok' => true, 'code' => 'already'];
        }

        if (file_put_contents($file, $updated, LOCK_EX) === false || !self::bridgeRegistered()) {
            return ['ok' => false, 'code' => 'write'];
        }

        return ['ok' => true, 'code' => 'enabled'];
    }

    /**
     * Takes the bridge out again and, when AI Friendly created the file, deletes it.
     *
     * @return array{ok: bool, code: string}
     */
    public static function disableBridge(): array
    {
        $file = self::functionsFile();

        if (!is_file($file)) {
            return ['ok' => true, 'code' => 'absent'];
        }

        $content = file_get_contents($file);

        if ($content === false || !is_writable($file)) {
            return ['ok' => false, 'code' => 'not_writable'];
        }

        $cleaned = self::removeBridge($content);

        if ($cleaned === $content) {
            return ['ok' => true, 'code' => 'absent'];
        }

        if (self::isOnlyOurs($content, $cleaned)) {
            return @unlink($file) ? ['ok' => true, 'code' => 'removed'] : ['ok' => false, 'code' => 'write'];
        }

        if (file_put_contents($file, $cleaned, LOCK_EX) === false) {
            return ['ok' => false, 'code' => 'write'];
        }

        return ['ok' => true, 'code' => 'removed'];
    }

    /**
     * Entry point called by the bridge on every `forseo_structured_data` run.
     *
     * @param mixed $data Whatever 4SEO passes; returned untouched when inactive.
     * @return mixed
     */
    public static function inject($data)
    {
        $options = Options::get();

        if (empty($options['schema_enabled'])) {
            return $data;
        }

        $mode = OutputMode::resolve($options);

        // In core mode the identity travels with the Joomla graph, so what 4SEO
        // publishes for the same entities would only repeat it.
        if ($mode === OutputMode::CORE) {
            return self::withoutSiteEntities($data);
        }

        if ($mode !== OutputMode::FORSEO) {
            return $data;
        }

        if (!is_array($data) || !$data) {
            return $data;
        }

        $ours = self::identityNode($options);

        if (!$ours) {
            return $data;
        }

        // A single document, when 4SEO hands one over directly.
        if (self::isDocument($data)) {
            return self::enrichDocument($data, $ours, $options) ?? $data;
        }

        // The documented case: a map of rule name to document. Only the first
        // document carrying our entity is enriched; doing it in several would
        // repeat the same identity across script tags.
        foreach ($data as $rule => $document) {
            if (!is_array($document) || !self::isDocument($document)) {
                continue;
            }

            $enriched = self::enrichDocument($document, $ours, $options);

            if ($enriched !== null) {
                $data[$rule] = $enriched;

                return $data;
            }
        }

        return $data;
    }

    /**
     * Removes the site-level entities from a map of rule name to document,
     * dropping documents that end up empty. Page-level documents such as the
     * breadcrumb are returned untouched.
     *
     * @param mixed $data
     * @return mixed
     */
    public static function withoutSiteEntities($data)
    {
        if (!is_array($data) || !$data || self::isDocument($data)) {
            return $data;
        }

        $site = ['WebSite', 'Organization', 'LocalBusiness', 'EducationalOrganization', 'PostalAddress', 'GeoCoordinates', 'ImageObject'];

        foreach ($data as $rule => $document) {
            if (!is_array($document) || !self::isDocument($document)) {
                continue;
            }

            $nodes = isset($document['@graph']) && is_array($document['@graph']) ? $document['@graph'] : [$document];
            $kept = array_values(array_filter($nodes, static function ($node) use ($site): bool {
                $types = is_array($node) ? (array) ($node['@type'] ?? []) : [];

                return !$types || !array_intersect($types, $site);
            }));

            if (count($kept) === count($nodes)) {
                continue;
            }

            if (!$kept) {
                unset($data[$rule]);
            } elseif (isset($document['@graph'])) {
                $data[$rule]['@graph'] = $kept;
            } else {
                $data[$rule] = $kept[0];
            }
        }

        return $data;
    }

    private static function isDocument(array $value): bool
    {
        return isset($value['@graph']) || isset($value['@type']);
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $ours
     * @return array<string, mixed>|null Null when the document holds no entity of ours.
     */
    private static function enrichDocument(array $document, array $ours, array $options): ?array
    {
        $wrapped = isset($document['@graph']) && is_array($document['@graph']);
        $nodes = $wrapped ? $document['@graph'] : [$document];

        $key = self::identityKey($nodes, $options);

        if ($key === null) {
            return null;
        }

        $nodes[$key] = self::enrich($nodes[$key], $ours);

        if ($wrapped) {
            $document['@graph'] = $nodes;

            return $document;
        }

        return $nodes[$key];
    }

    /**
     * Locates the node 4SEO already publishes for the site owner.
     *
     * The target has to be type-compatible with our entity: a person's jobTitle
     * and knowsAbout have no business on an organisation node. A Person only
     * qualifies when its id marks it as a site-level default, because the other
     * Person nodes 4SEO emits are article authors.
     *
     * @param array<int|string, mixed> $nodes
     * @return int|string|null
     */
    private static function identityKey(array $nodes, array $options)
    {
        $candidates = ($options['schema_entity_type'] ?? 'Person') === 'Organization'
            ? ['Organization', 'LocalBusiness']
            : ['Person'];

        foreach ($candidates as $wanted) {
            foreach ($nodes as $key => $node) {
                if (!is_array($node) || ($node['@type'] ?? '') !== $wanted || empty($node['@id'])) {
                    continue;
                }

                if ($wanted === 'Person' && !str_contains(strtolower((string) $node['@id']), 'default')) {
                    continue;
                }

                return $key;
            }
        }

        return null;
    }

    /**
     * Copies our fields onto 4SEO's node without ever removing or overwriting
     * what is already there.
     *
     * Deliberately not SchemaBuilder::merge(): that also runs the graph through
     * clean(), which strips empty values recursively. Applied to another
     * extension's payload it silently deletes its keys, and any node that
     * reduces to empty disappears entirely.
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $ours
     * @return array<string, mixed>
     */
    private static function enrich(array $target, array $ours): array
    {
        foreach ($ours as $key => $value) {
            // Identity and type belong to 4SEO: an Organization of theirs must
            // not become a Person of ours.
            if ($key === '@id' || $key === '@type' || $value === '' || $value === [] || $value === null) {
                continue;
            }

            $existing = $target[$key] ?? null;

            if ($existing === null || $existing === '' || $existing === []) {
                $target[$key] = $value;
                continue;
            }

            if (is_array($existing) && is_array($value) && array_is_list($existing) && array_is_list($value)) {
                $target[$key] = array_values(array_unique(array_merge($existing, $value), SORT_REGULAR));
            }
        }

        return $target;
    }

    /**
     * Our identity node, extracted from the graph this extension would build.
     *
     * @return array<string, mixed>|null
     */
    private static function identityNode(array $options): ?array
    {
        $identityId = rtrim(Uri::root(), '/') . '/#identity';

        foreach ((new SchemaBuilder($options))->graph() as $node) {
            if (is_array($node) && ($node['@id'] ?? '') === $identityId) {
                return $node;
            }
        }

        return null;
    }
}
