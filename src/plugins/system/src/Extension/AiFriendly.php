<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Plugin\System\AiFriendly\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\EventInterface;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use Sernicola\Library\AiFriendly\Integration\CoreSchema;
use Sernicola\Library\AiFriendly\Markdown\Generator;
use Sernicola\Library\AiFriendly\Provider\JoomlaContentProvider;
use Sernicola\Library\AiFriendly\Schema\ListingSchema;
use Sernicola\Library\AiFriendly\Schema\OutputMode;
use Sernicola\Library\AiFriendly\Schema\SchemaBuilder;
use Sernicola\Library\AiFriendly\Service\Factory as AiFactory;
use Sernicola\Library\AiFriendly\Service\LlmsBuilder;
use Sernicola\Library\AiFriendly\Service\Regenerator;
use Sernicola\Library\AiFriendly\Service\RegenerationJob;
use Sernicola\Library\AiFriendly\Service\FullCorpus;
use Sernicola\Library\AiFriendly\Service\ResponseCache;
use Sernicola\Library\AiFriendly\Support\Lang;
use Sernicola\Library\AiFriendly\Support\Options;
use Sernicola\Library\AiFriendly\Support\Path;

final class AiFriendly extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;
    protected $allowLegacyListeners = false;
    private const VERSION = '2.6.0';

    /** Clean path of a .md request handed over to the Joomla router. */
    private ?string $deferred = null;
    private array $previousUserNames = [];

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onEarlyRoute',
            'onAfterRoute' => 'onAfterRoute',
            'onError' => 'onError',
            'onBeforeCompileHead' => 'onBeforeCompileHead',
            'onContentAfterSave' => 'onContentAfterSave',
            'onContentAfterDelete' => 'onContentAfterDelete',
            'onContentChangeState' => 'onContentChangeState',
            'onUserBeforeSave' => 'onUserBeforeSave',
            'onUserAfterSave' => 'onUserAfterSave',
            // Last of all: the Schema.org plugins of the article, event and so on add their
            // nodes in listeners of their own, and the page types need those nodes to exist.
            'onSchemaBeforeCompileHead' => ['onSchemaBeforeCompileHead', Priority::MIN],
        ];
    }

    /**
     * First attempt, before Joomla routes.
     *
     * A path that resolves here is served without paying for routing at all.
     * One that does not is handed to the router instead of being refused: a SEF
     * URL is built from the menu tree, so its segments are usually menu aliases
     * with no relation to the article's own.
     */
    public function onEarlyRoute(): void
    {
        $this->route(false);
    }

    public function onAfterRoute(): void
    {
        // Joomla has now resolved the rewritten path, and SiteRoute reads that
        // result straight out of the application.
        if ($this->deferred !== null) {
            $this->serveMarkdown($this->deferred);
            return;
        }
        $this->route(true);
    }

    /**
     * Answers the plain-text 404 when routing the rewritten path fails.
     *
     * Without this a bogus .md would get the site's HTML error page, and a
     * client that asked for Markdown deserves the same answer as before.
     * Anything that is not a 404 is left alone, so a genuine fault still
     * surfaces as a fault.
     */
    public function onError(EventInterface $event): void
    {
        if ($this->deferred === null) return;
        $error = method_exists($event, 'getError') ? $event->getError() : null;
        if ($error instanceof \Throwable && (int) $error->getCode() !== 404) return;
        $this->notFound('route-failed');
    }

    private function route(bool $final): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site')) return;
        $uri = Uri::getInstance();
        $path = Path::normalize($uri->getPath());
        $base = Path::normalize(Uri::base(true));
        if ($base !== '/' && str_starts_with($path, $base)) $path = Path::normalize(substr($path, strlen($base)) ?: '/');
        if ($path === '/llms.txt') {
            $this->serveLlms();
            return;
        }
        if ($path === '/llms-full.txt') {
            $this->serveFullCorpus();
            return;
        }
        if (!preg_match('#(?:\.md|/index\.html\.md)$#i', $path)) return;
        $clean = Path::withoutMarkdownSuffix($path);
        $this->serveMarkdown($clean, $final);
        // Unresolved and still early: strip the suffix from the live URI so
        // Joomla routes the real page. route() clones this very instance, so
        // the rewrite reaches the router without touching anything else.
        if (!$final) $this->defer($clean, $uri);
    }

    /**
     * @param Uri $uri The request URI singleton, rewritten in place.
     */
    private function defer(string $clean, Uri $uri): void
    {
        $path = $uri->getPath();
        $rewritten = Path::stripMarkdownSuffix($path);
        if ($rewritten === $path) return;
        $uri->setPath($rewritten);
        $this->deferred = $clean;
    }

    public function onBeforeCompileHead(): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site')) return;
        $document = $app->getDocument();
        if (!$document instanceof HtmlDocument) return;
        $document->addHeadLink(rtrim(Uri::root(), '/') . '/llms.txt', 'alternate', 'rel', ['type' => 'text/plain', 'title' => 'LLM Instructions']);
        $item = $this->currentItem();
        $options = Options::get();
        if ($item && AiFactory::rules()->allows($item, 'head')) {
            $document->addHeadLink(Path::markdownUrl($item->canonicalUrl), 'alternate', 'rel', ['type' => 'text/markdown', 'title' => 'Markdown']);
        }
        if (empty($options['schema_enabled'])) return;
        $this->printListingSchema($document);
        // In 4SEO mode the graph is injected by the bridge, so nothing is printed here.
        if (OutputMode::resolve($options) === OutputMode::STANDALONE) {
            $json = (new SchemaBuilder($options))->jsonLd($item);
            if ($json !== '') $document->addCustomTag('<script type="application/ld+json">' . $json . '</script>');
        }
    }

    /**
     * Joomla Schema.org publishes its graph from this event, on every page.
     * The handler lays the AI Friendly identity over the nodes Joomla built.
     */
    public function onSchemaBeforeCompileHead(EventInterface $event): void
    {
        try {
            $schema = $event->getArgument('subject');
            if (!is_object($schema) || !method_exists($schema, 'get') || !method_exists($schema, 'set')) return;
            $graph = $schema->get('@graph');
            if (!is_array($graph) || !$graph) return;
            $item = $this->currentItem();
            $schema->set('@graph', CoreSchema::graph(
                $graph,
                $item,
                fn (): array => $this->categoryChain($item),
                fn (): array => $this->storedText($item)
            ));
        } catch (\Throwable) {
            // A fault on our side must never take the structured data down.
        }
    }

    public function onContentAfterSave(EventInterface $event): void
    {
        RegenerationJob::create()->markDirty();
        $options = Options::get();
        if (empty($options['regenerate_on_save']) || empty($options['static_md_files'])) return;
        $args = array_values($event->getArguments());
        $item = $args[1] ?? null;
        $id = is_object($item) ? (int) ($item->id ?? 0) : 0;
        if (!$id) return;
        $provider = new JoomlaContentProvider();
        $content = $provider->find((string) $id);
        if (!$content) return;
        $repository = AiFactory::repository();
        if (!AiFactory::rules($repository)->allows($content, 'save')) return;
        try {
            AiFactory::staticStore($repository)->save($content, (new Generator($options))->generate($content));
            $repository->addEvent('regenerate_on_save', ['key' => $content->key()]);
        } catch (\Throwable $error) {
            $repository->saveState($content->key(), ['last_error' => $error->getMessage()]);
            $repository->addEvent('regenerate_on_save_error', ['key' => $content->key(), 'message' => $error->getMessage()], 'error');
        }
    }

    public function onContentChangeState(EventInterface $event): void
    {
        RegenerationJob::create()->markDirty();
        AiFactory::repository()->addEvent('content_state_change', ['arguments' => array_keys($event->getArguments())]);
    }

    public function onContentAfterDelete(EventInterface $event): void
    {
        RegenerationJob::create()->markDirty();
    }

    public function onUserBeforeSave(EventInterface $event): void
    {
        $user = method_exists($event, 'getUser') ? $event->getUser() : $event->getArgument('user', []);
        if (is_array($user) && !empty($user['id'])) $this->previousUserNames[(int) $user['id']] = (string) ($user['name'] ?? '');
    }

    public function onUserAfterSave(EventInterface $event): void
    {
        if (method_exists($event, 'getSavingResult') && !$event->getSavingResult()) return;
        $user = method_exists($event, 'getUser') ? $event->getUser() : $event->getArgument('user', []);
        if (!is_array($user) || empty($user['id'])) return;
        $id = (int) $user['id'];
        if (!array_key_exists($id, $this->previousUserNames) || $this->previousUserNames[$id] === (string) ($user['name'] ?? '')) return;
        Options::save(['author_cache_epoch' => microtime(true)]);
        $jobs = RegenerationJob::create();
        $jobs->markDirty();
        $jobs->startAuthor($id);
    }

    private function serveFullCorpus(): never
    {
        $options = Options::get();
        $state = FullCorpus::state(!empty($options['llms_full_enabled']), (string) ($options['llms_full_hash'] ?? ''));
        if (!$state['ready']) $this->notFound('corpus-unavailable');
        $app = $this->getApplication();
        $app->setHeader('Content-Type', 'text/plain; charset=UTF-8', true);
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $app->setHeader('X-Robots-Tag', 'noindex, follow', true);
        $app->setHeader('Content-Length', (string) $state['size'], true);
        $app->sendHeaders();
        readfile(FullCorpus::target());
        $app->close();
    }

    private function serveLlms(): never
    {
        $app = $this->getApplication();
        $options = Options::get();
        $debugRequested = (bool) $app->getInput()->getBool('debug');
        $debug = $debugRequested && $app->getIdentity()->authorise('core.manage', 'com_aifriendly');
        $cache = $this->cache($debug);
        $body = $cache->remember($cache->id('llms'), static fn (): string => (new LlmsBuilder(AiFactory::registry(), AiFactory::rules(), $options))->build());
        $this->respond(200, 'text/plain; charset=UTF-8', $body, [
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, follow',
            'Link' => '<' . rtrim(Uri::root(), '/') . '/llms.txt>; rel="canonical"',
            'X-AI-Friendly-Version' => self::VERSION,
            'X-AI-Friendly-Source' => 'dynamic',
            'X-AI-Friendly-LLMS-Length' => (string) strlen($body),
            'X-AI-Friendly-Debug-Requested' => $debugRequested ? '1' : '0',
            'X-AI-Friendly-Debug-Admin' => $debug ? '1' : '0',
        ], !$debug);
    }

    /**
     * @param bool $final When false, an unresolved path returns instead of
     *                    answering 404, so a later hook can try again.
     */
    private function serveMarkdown(string $path, bool $final = true): void
    {
        $app = $this->getApplication();
        $language = $app->getLanguage()->getTag();
        $options = Options::get();
        $repository = AiFactory::repository();
        $registry = AiFactory::registry();
        $rules = AiFactory::rules($repository);
        $item = $registry->resolve($path, $language) ?: $registry->resolve($path, null);
        $debugRequested = (bool) $app->getInput()->getBool('debug');
        $debug = $debugRequested && $app->getIdentity()->authorise('core.manage', 'com_aifriendly');
        if (!$item) {
            if (Path::normalize($path) === '/') {
                $this->serveHomepage($registry, $rules, $options, $debugRequested, $debug);
            }
            $provider = new JoomlaContentProvider();
            if (!empty($options['include_categories']) && ($category = $provider->categoryByPath($path, $language))) {
                $this->serveCategory($category, $provider, $rules, $debugRequested, $debug);
            }
            if (!$final) {
                return;
            }
            $this->notFound('unresolved');
        }
        // Distinct reason: the content exists but a rule withholds it, which
        // from the outside looks exactly like a path that resolves to nothing.
        if (!$rules->allows($item, 'serve')) $this->notFound('excluded');
        $source = 'dynamic';
        $markdown = null;
        if (!empty($options['static_md_files']) && !$debug) {
            $markdown = AiFactory::staticStore($repository)->get($item);
            if ($markdown !== null) $source = 'static';
        }
        if ($markdown === null) {
            $cache = $this->cache($debug);
            $markdown = $cache->remember(
                $cache->id('md', $item->key(), $item->modified),
                static fn (): string => (new Generator($options))->generate($item)
            );
        }
        if ($debug) {
            $debugBlock = "---\n## DEBUG INFO\n\n**Content key:** " . $item->key() . "\n\n**Path:** " . Path::normalize($path) . "\n\n**Source:** {$source}\n\n---\n\n";
            $markdown = preg_replace('/^(---\n.*?\n---\n\n)/s', '$1' . $debugBlock, $markdown, 1) ?: $debugBlock . $markdown;
        }
        $this->respond(200, 'text/markdown; charset=UTF-8', $markdown, [
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, follow',
            'Link' => '<' . Path::publicUrl($item->canonicalUrl) . '>; rel="canonical"',
            'X-AI-Friendly-Source' => $source, 'X-AI-Friendly-Version' => self::VERSION,
            'X-AI-Friendly-MD-Length' => (string) strlen($markdown),
            'X-AI-Friendly-Debug-Requested' => $debugRequested ? '1' : '0',
            'X-AI-Friendly-Debug-Admin' => $debug ? '1' : '0',
        ], !$debug);
    }

    private function serveHomepage($registry, $rules, array $options, bool $debugRequested, bool $debug): never
    {
        $config = $this->getApplication()->getConfig();
        $lines = ['# ' . (string) $config->get('sitename', 'Joomla'), ''];
        $description = trim((string) $config->get('MetaDesc', ''));
        if ($description !== '') {
            $lines[] = $description;
            $lines[] = '';
        }
        $limit = max(1, (int) ($options['articles_limit'] ?? 100));
        $items = [];
        foreach ($registry->all(['language' => $this->getApplication()->getLanguage()->getTag()], $limit) as $item) {
            if ($rules->allows($item, 'homepage')) {
                $items[] = '- [' . $item->title . '](' . Path::markdownUrl($item->canonicalUrl) . ')' . ($item->excerpt !== '' ? ': ' . $item->excerpt : '');
            }
        }
        if ($items !== []) {
            $lines[] = '## ' . Lang::text('PLG_SYSTEM_AIFRIENDLY_HOMEPAGE_HEADING', 'Contents');
            $lines[] = '';
            array_push($lines, ...$items);
        }
        if ($debug) {
            $lines[] = '';
            $lines[] = '> Debug: homepage collection, ' . count($items) . ' elementi inclusi.';
        }
        $body = rtrim(implode("\n", $lines)) . "\n";
        $this->respond(200, 'text/markdown; charset=UTF-8', $body, [
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, follow',
            'Link' => '<' . rtrim(Uri::root(), '/') . '/>; rel="canonical"',
            'X-AI-Friendly-Source' => 'homepage',
            'X-AI-Friendly-Version' => self::VERSION,
            'X-AI-Friendly-MD-Length' => (string) strlen($body),
            'X-AI-Friendly-Debug-Requested' => $debugRequested ? '1' : '0',
            'X-AI-Friendly-Debug-Admin' => $debug ? '1' : '0',
        ], !$debug);
    }

    private function serveCategory(object $category, JoomlaContentProvider $provider, $rules, bool $debugRequested, bool $debug): never
    {
        $lines = ['# ' . $category->title, ''];
        if (!empty($category->description)) $lines[] = trim(strip_tags((string) $category->description)) . "\n";
        foreach ($provider->list(['category_id' => (int) $category->id, 'language' => (string) $category->language], 100) as $item) {
            if ($rules->allows($item, 'archive')) $lines[] = '- [' . $item->title . '](' . Path::markdownUrl($item->canonicalUrl) . ')' . ($item->excerpt ? ': ' . $item->excerpt : '');
        }
        $body = implode("\n", $lines) . "\n";
        $this->respond(200, 'text/markdown; charset=UTF-8', $body, [
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, follow',
            'Link' => '<' . $this->categoryUrl($category) . '>; rel="canonical"',
            'X-AI-Friendly-Source' => 'archive', 'X-AI-Friendly-Version' => self::VERSION,
            'X-AI-Friendly-MD-Length' => (string) strlen($body),
            'X-AI-Friendly-Debug-Requested' => $debugRequested ? '1' : '0', 'X-AI-Friendly-Debug-Admin' => $debug ? '1' : '0',
        ], !$debug);
    }

    /**
     * Absolute URL of the HTML category page this collection mirrors.
     *
     * The other three endpoints have always advertised a canonical; this one
     * was the exception, so a crawler had no way to relate the .md back to the
     * page it came from.
     */
    private function categoryUrl(object $category): string
    {
        $url = Route::link(
            'site',
            'index.php?option=com_content&view=category&id=' . (int) $category->id . '&lang=' . rawurlencode((string) ($category->language ?? '*')),
            true,
            Route::TLS_IGNORE,
            true
        );

        return str_starts_with($url, 'http') ? $url : rtrim(Uri::root(), '/') . '/' . ltrim($url, '/');
    }

    /**
     * The 404 every Markdown endpoint answers with.
     *
     * $reason names the branch that produced it. The body stays the same for
     * everyone, but a header makes the two indistinguishable failures — nothing
     * resolved, or something resolved and was withheld — tellable apart without
     * access to the site.
     */
    private function notFound(string $reason): never
    {
        $this->respond(404, 'text/plain; charset=UTF-8', Lang::text('PLG_SYSTEM_AIFRIENDLY_MD_UNAVAILABLE', 'Markdown content is not available.'), [
            'X-AI-Friendly-Version' => self::VERSION,
            'X-AI-Friendly-Reason' => $reason,
        ]);
    }

    /**
     * Joomla Schema.org publishes a graph for articles only. The listing views
     * of com_content (category, categories, archive, featured) get theirs here.
     */
    private function printListingSchema(HtmlDocument $document): void
    {
        $app = $this->getApplication();
        $input = $app->getInput();
        $view = $input->getCmd('view');
        if ($input->getCmd('option') !== 'com_content' || !in_array($view, ['category', 'categories', 'archive', 'featured'], true)) return;
        try {
            $items = [];
            if ($view === 'category' && ($categoryId = $input->getInt('id')) > 0) {
                $ids = $this->categoryBranch($categoryId);
                foreach ((new JoomlaContentProvider())->list(['category_ids' => $ids, 'language' => $app->getLanguage()->getTag()], ListingSchema::LIMIT) as $item) {
                    if (!$item->accessible || !$item->published || !empty($item->metadata['noindex'])) continue;
                    $items[] = ['url' => Path::publicUrl($item->canonicalUrl), 'name' => $item->title];
                }
            }
            $graph = CoreSchema::listing([
                'url' => $this->listingUrl(),
                'title' => (string) $document->getTitle(),
                'description' => (string) $document->getDescription(),
                'language' => $app->getLanguage()->getTag(),
                'site' => (string) $app->get('sitename'),
            ], array_map(static fn (array $row): array => ['url' => html_entity_decode($row['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'name' => $row['name']], $items));
            if ($graph) {
                $document->addCustomTag('<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>');
            }
        } catch (\Throwable) {
            // A fault on our side must never break the page.
        }
    }

    /**
     * The address of the listing without pagination or tracking parameters. On
     * a site without friendly URLs the parameters that identify the page stay,
     * otherwise every listing would share one address.
     */
    private function listingUrl(): string
    {
        $uri = Uri::getInstance();
        $url = $uri->toString(['scheme', 'host', 'port', 'path']);
        $query = $uri->getQuery(true);
        if (isset($query['option'])) {
            $keep = array_intersect_key($query, array_flip(['option', 'view', 'layout', 'id', 'catid', 'Itemid']));
            $url .= '?' . http_build_query($keep);
        }
        return $url;
    }

    /**
     * A category and everything below it.
     *
     * @return array<int, int>
     */
    private function categoryBranch(int $categoryId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select($db->quoteName('c.id'))
            ->from($db->quoteName('#__categories', 'c'))
            ->join('INNER', $db->quoteName('#__categories', 'p') . ' ON c.lft >= p.lft AND c.rgt <= p.rgt AND c.extension = p.extension')
            ->where($db->quoteName('p.id') . ' = :id')
            ->where($db->quoteName('c.published') . ' = 1')
            ->bind(':id', $categoryId, \Joomla\Database\ParameterType::INTEGER);
        return array_map('intval', $db->setQuery($query)->loadColumn() ?: [$categoryId]);
    }

    private ?\Sernicola\Library\AiFriendly\Dto\ContentItem $current = null;
    private bool $currentResolved = false;

    private function currentItem(): ?\Sernicola\Library\AiFriendly\Dto\ContentItem
    {
        // Read twice per request (head links, schema): resolve it once.
        if ($this->currentResolved) return $this->current;
        $this->currentResolved = true;
        $input = $this->getApplication()->getInput();
        if ($input->getCmd('option') !== 'com_content' || $input->getCmd('view') !== 'article') return null;
        return $this->current = (new JoomlaContentProvider())->find((string) $input->getInt('id'), $this->getApplication()->getLanguage()->getTag());
    }

    /**
     * The article's category followed by its ancestors, so a rule on a parent
     * category reaches every page below it.
     *
     * @return array<int, int>
     */
    private function categoryChain(\Sernicola\Library\AiFriendly\Dto\ContentItem $item): array
    {
        $catId = (int) ($item->taxonomies['category_ids'][0] ?? 0);
        if ($catId === 0) return [];
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select($db->quoteName('p.id'))
            ->from($db->quoteName('#__categories', 'c'))
            ->join('INNER', $db->quoteName('#__categories', 'p') . ' ON p.lft <= c.lft AND p.rgt >= c.rgt AND p.extension = c.extension')
            ->where($db->quoteName('c.id') . ' = :id')
            ->order($db->quoteName('p.lft') . ' DESC')
            ->bind(':id', $catId, \Joomla\Database\ParameterType::INTEGER);
        return array_map('intval', $db->setQuery($query)->loadColumn() ?: [$catId]);
    }

    /** @return array{introtext:string, fulltext:string, fields:array<string,string>} */
    private function storedText(\Sernicola\Library\AiFriendly\Dto\ContentItem $item): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $id = (int) $item->id;
        $query = $db->getQuery(true)
            ->select([$db->quoteName('introtext'), $db->quoteName('fulltext')])
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, \Joomla\Database\ParameterType::INTEGER);
        $row = $db->setQuery($query)->loadObject();
        $fieldsQuery = $db->getQuery(true)
            ->select([$db->quoteName('f.name'), $db->quoteName('v.value')])
            ->from($db->quoteName('#__fields', 'f'))
            ->join('INNER', $db->quoteName('#__fields_values', 'v') . ' ON v.field_id = f.id')
            ->where($db->quoteName('f.context') . ' = ' . $db->quote('com_content.article'))
            ->where($db->quoteName('v.item_id') . ' = ' . $id);
        $fields = [];
        foreach ($db->setQuery($fieldsQuery)->loadAssocList() ?: [] as $field) {
            $fields[(string) $field['name']] = (string) $field['value'];
        }
        return ['introtext' => (string) ($row->introtext ?? ''), 'fulltext' => (string) ($row->fulltext ?? ''), 'fields' => $fields];
    }

    /**
     * Caching is bypassed entirely in debug mode: that output is tailored to an
     * administrator and must never be stored, let alone served to anyone else.
     */
    private function cache(bool $debug): ResponseCache
    {
        return new ResponseCache($debug ? 0 : ResponseCache::ttl(Options::get()));
    }

    /**
     * @param bool $cacheable False for administrator-only output, which must not
     *                        pick up public cache headers on its way through a proxy.
     */
    private function respond(int $status, string $contentType, string $body, array $headers = [], bool $cacheable = true): never
    {
        $app = $this->getApplication();
        $ttl = $status === 200 && $cacheable ? ResponseCache::ttl(Options::get()) : 0;
        $etag = $ttl > 0 ? '"' . md5($body) . '"' : '';

        // A matching ETag means the client already holds this exact document.
        if ($etag !== '' && trim((string) $app->getInput()->server->getString('HTTP_IF_NONE_MATCH', '')) === $etag) {
            http_response_code(304);
            $app->setHeader('status', '304', true);
            $app->setHeader('ETag', $etag, true);
            $app->setHeader('Cache-Control', 'public, max-age=' . $ttl, true);
            $app->sendHeaders();
            $app->close();
        }

        http_response_code($status);
        $app->setHeader('status', (string) $status, true);
        $app->setHeader('Content-Type', $contentType, true);

        if ($ttl > 0) {
            $app->setHeader('Cache-Control', 'public, max-age=' . $ttl, true);
            $app->setHeader('ETag', $etag, true);
        } else {
            $app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
            $app->setHeader('Pragma', 'no-cache', true);
            $app->setHeader('Expires', '0', true);
        }

        foreach ($headers as $name => $value) $app->setHeader($name, (string) $value, true);
        $app->setHeader('Content-Length', (string) strlen($body), true);
        $app->sendHeaders();
        echo $body;
        $app->close();
    }
}
