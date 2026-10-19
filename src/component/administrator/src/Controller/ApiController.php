<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Component\AiFriendly\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Sernicola\Library\AiFriendly\Integration\ForSeo;
use Sernicola\Library\AiFriendly\Markdown\Converter;
use Sernicola\Library\AiFriendly\Service\Diagnostics;
use Sernicola\Library\AiFriendly\Service\Factory as AiFactory;
use Sernicola\Library\AiFriendly\Service\LlmsBuilder;
use Sernicola\Library\AiFriendly\Service\Regenerator;
use Sernicola\Library\AiFriendly\Service\RegenerationJob;
use Sernicola\Library\AiFriendly\Service\FullCorpus;
use Sernicola\Library\AiFriendly\Support\Options;

final class ApiController extends BaseController
{
    public function overview(): void
    {
        $this->guard();
        $this->respond($this->diagnosticsService()->run());
    }

    public function diagnostics(): void
    {
        $this->overview();
    }

    public function content(): void
    {
        $this->guard();
        $input = Factory::getApplication()->getInput();
        $page = max(1, $input->getInt('page', 1));
        $limit = max(10, min(100, $input->getInt('limit', 25)));
        $filters = ['search' => $input->getString('search'), 'language' => $input->getString('language')];
        $repository = AiFactory::repository();
        $registry = AiFactory::registry();
        $rules = AiFactory::rules($repository);
        $items = [];
        foreach ($registry->all($filters, $limit, ($page - 1) * $limit) as $item) {
            $row = $item->toArray();
            $row['excluded'] = $repository->isExcluded($item->key());
            $row['included'] = $rules->allows($item, 'admin');
            $items[] = $row;
        }
        $this->respond(['items' => $items, 'page' => $page, 'limit' => $limit, 'total' => $registry->count($filters)]);
    }

    public function toggle(): void
    {
        $this->guard();
        $key = Factory::getApplication()->getInput()->getString('key');
        if ($key === '') throw new \InvalidArgumentException(Text::_('COM_AIFRIENDLY_ERR_CONTENT_KEY_MISSING'));
        $repository = AiFactory::repository();
        $excluded = $repository->toggleExcluded($key);
        RegenerationJob::create()->markDirty();
        $repository->addEvent('content_exclusion_toggle', ['key' => $key, 'excluded' => $excluded]);
        $this->respond(['key' => $key, 'excluded' => $excluded]);
    }

    public function snapshots(): void
    {
        $this->guard();
        $this->respond(['items' => AiFactory::repository()->snapshots(50)]);
    }

    public function snapshotCreate(): void
    {
        $this->guard();
        $options = Options::get();
        $content = (new LlmsBuilder(AiFactory::registry(), AiFactory::rules(), $options))->build();
        $reason = Factory::getApplication()->getInput()->getString('reason', 'manual');
        $this->respond(AiFactory::repository()->createSnapshot($content, $reason));
    }

    public function snapshotRestore(): void
    {
        $this->guard();
        $repository = AiFactory::repository();
        $snapshot = $repository->snapshot(Factory::getApplication()->getInput()->getInt('id'));
        if (!$snapshot) throw new \RuntimeException(Text::_('COM_AIFRIENDLY_ERR_SNAPSHOT_MISSING'));
        $this->saveOptions(['llms_content' => (string) $snapshot['content']]);
        $repository->addEvent('llms_snapshot_restore', ['id' => (int) $snapshot['id']]);
        $this->respond(['restored' => true, 'id' => (int) $snapshot['id']]);
    }

    public function snapshotDiff(): void
    {
        $this->guard();
        $input = Factory::getApplication()->getInput();
        $this->respond(AiFactory::repository()->diffSnapshots($input->getInt('left'), $input->getInt('right')));
    }

    public function preview(): void
    {
        $this->guard();
        $input = Factory::getApplication()->getInput();
        $content = $input->post->get('content', '', 'raw');
        if ($content === '') $content = (new LlmsBuilder(AiFactory::registry(), AiFactory::rules(), Options::get()))->build();
        $links = [];
        preg_match_all('/\[[^\]]+\]\((https?:\/\/[^)]+)\)/i', $content, $matches);
        foreach (array_unique($matches[1] ?? []) as $url) $links[] = ['url' => $url, 'valid' => (bool) filter_var($url, FILTER_VALIDATE_URL)];
        $this->respond(['content' => $content, 'html' => nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')), 'lines' => substr_count($content, "\n") + 1, 'tokens' => (int) ceil(mb_strlen($content) / 4), 'links' => $links]);
    }

    public function regenerate(): void
    {
        $this->regenerationStart();
    }

    public function regenerationStart(): void
    {
        $this->guard();
        $this->respond(RegenerationJob::create()->start((bool) Factory::getApplication()->getInput()->getBool('force'), 'admin'));
    }

    public function regenerationStep(): void
    {
        $this->guard();
        $this->respond(RegenerationJob::create()->step());
    }

    public function regenerationStatus(): void
    {
        $this->guard();
        $this->respond(RegenerationJob::create()->status());
    }

    public function prune(): void
    {
        $this->guard();
        $options = Options::get();
        $repository = AiFactory::repository();
        $registry = AiFactory::registry();
        $regenerator = new Regenerator($registry, AiFactory::rules($repository), AiFactory::staticStore($repository), $repository, $options);
        $this->respond($regenerator->prune());
    }

    public function clearVersions(): void
    {
        $this->guard();
        $count = AiFactory::staticStore()->clear();
        AiFactory::repository()->addEvent('static_versions_clear', ['count' => $count]);
        $this->respond(['deleted' => $count]);
    }

    /**
     * Adds or removes the optional 4SEO bridge. It edits a file that belongs to 4SEO,
     * so it only runs from an explicit click in the Hub and is reverted on uninstall.
     */
    public function forseoBridge(): void
    {
        $this->guard();
        $enable = (bool) Factory::getApplication()->getInput()->getBool('enable');
        $result = $enable ? ForSeo::enableBridge() : ForSeo::disableBridge();

        if (!$result['ok']) {
            $key = ['missing' => 'MISSING', 'not_writable' => 'NOT_WRITABLE', 'format' => 'FORMAT'][$result['code']] ?? 'WRITE';
            throw new \RuntimeException(Text::sprintf('COM_AIFRIENDLY_ERR_FORSEO_' . $key, ForSeo::functionsFile()));
        }

        AiFactory::repository()->addEvent('forseo_bridge', ['enabled' => $enable]);
        $this->respond(['registered' => ForSeo::bridgeRegistered(), 'code' => $result['code']]);
    }

    public function events(): void
    {
        $this->guard();
        $this->respond(['items' => AiFactory::repository()->events(50)]);
    }

    public function save(): void
    {
        $this->guard();
        $input = Factory::getApplication()->getInput();
        $posted = $input->post->get('options', [], 'array');
        $defaults = Options::defaults();
        $clean = [];
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $posted)) {
                continue;
            }
            $value = $posted[$key];
            if (is_int($default)) $value = (int) $value;
            elseif (is_array($default)) {
                if ($key === 'schema_services' && is_string($value)) {
                    $decoded = json_decode($value, true);
                    $value = is_array($decoded) ? $decoded : [];
                } else {
                    $value = is_array($value) ? $value : array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $item): bool => $item !== ''));
                }
            }
            else $value = is_string($value) ? trim($value) : $value;
            $clean[$key] = $value;
        }
        $before = Options::get();
        $this->saveOptions($clean);
        if (!empty($before['llms_full_enabled']) && array_key_exists('llms_full_enabled', $clean) && !$clean['llms_full_enabled']) {
            FullCorpus::removeOwned((string) ($before['llms_full_hash'] ?? ''));
            Options::save(['llms_full_hash' => '']);
        } elseif (!empty($clean['llms_full_enabled']) || array_intersect(array_keys($clean), ['include_articles', 'include_contacts', 'include_tags', 'exclude_categories', 'exclude_tags', 'exclude_url_patterns', 'exclude_noindex'])) {
            RegenerationJob::create()->markDirty();
        }
        AiFactory::repository()->addEvent('settings_save', ['keys' => array_keys($clean)]);
        $this->respond(['saved' => true, 'options' => Options::get()]);
    }

    private function diagnosticsService(): Diagnostics
    {
        $repository = AiFactory::repository();
        $registry = AiFactory::registry();
        $options = Options::get();
        return new Diagnostics($registry, AiFactory::rules($repository), AiFactory::staticStore($repository), $repository, $options);
    }

    private function guard(): void
    {
        $app = Factory::getApplication();
        if (!$app->getIdentity()->authorise('core.manage', 'com_aifriendly')) throw new \RuntimeException(Text::_('COM_AIFRIENDLY_ERR_UNAUTHORIZED'), 403);
        if (!Session::checkToken('request')) throw new \RuntimeException(Text::_('COM_AIFRIENDLY_ERR_INVALID_TOKEN'), 403);
    }

    private function saveOptions(array $changes): void
    {
        Options::save($changes);
    }

    private function respond($data): void
    {
        echo new JsonResponse($data);
        Factory::getApplication()->close();
    }
}
