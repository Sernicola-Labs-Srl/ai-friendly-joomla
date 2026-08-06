<?php

namespace Sernicola\Plugin\Content\AiFriendly\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Sernicola\Library\AiFriendly\Service\Factory as AiFactory;

/**
 * Adds an AI Friendly panel to the article editor.
 *
 * Exclusion was previously reachable only from the Hub content table, which is
 * the wrong place to look when you are already writing the article.
 *
 * The value does not live in #__content: it is stored against the same content
 * key the rest of the extension uses, so the Hub and the editor stay in sync
 * and toggling from either side has the same effect.
 */
final class AiFriendlyContent extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    private const CONTEXT = 'com_content.article';

    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareForm' => 'onContentPrepareForm',
            'onContentPrepareData' => 'onContentPrepareData',
            'onContentAfterSave' => 'onContentAfterSave',
        ];
    }

    public function onContentPrepareForm(EventInterface $event): void
    {
        $arguments = $event->getArguments();
        $form = $arguments['subject'] ?? ($arguments[0] ?? null);

        if (!$form instanceof Form || $form->getName() !== self::CONTEXT) {
            return;
        }

        Form::addFormPath(dirname(__DIR__, 2) . '/forms');
        $form->loadFile('article');
    }

    public function onContentPrepareData(EventInterface $event): void
    {
        $arguments = $event->getArguments();
        $context = $arguments['context'] ?? ($arguments[0] ?? '');
        $data = $arguments['subject'] ?? ($arguments[1] ?? null);

        if ($context !== self::CONTEXT || !is_object($data) || empty($data->id)) {
            return;
        }

        try {
            $repository = AiFactory::repository();
            $key = $this->contentKey((int) $data->id, (string) ($data->language ?? ''));
            $state = $repository->getState($key);

            $data->aifriendly = [
                'exclude' => $repository->isExcluded($key) ? 1 : 0,
                'last_generated' => (string) ($state['last_generated'] ?? ''),
            ];
        } catch (\Throwable) {
            // The editor must open even if our tables are missing.
        }
    }

    public function onContentAfterSave(EventInterface $event): void
    {
        $arguments = $event->getArguments();
        $context = $arguments['context'] ?? ($arguments[0] ?? '');
        $article = $arguments['subject'] ?? ($arguments[1] ?? null);

        if ($context !== self::CONTEXT || !is_object($article) || empty($article->id)) {
            return;
        }

        $form = $this->getApplication()->getInput()->get('jform', [], 'array');

        // Absent means the article was saved by something other than the editor
        // form, e.g. a batch action: leaving the flag alone is the safe choice.
        if (!array_key_exists('aifriendly', $form) || !is_array($form['aifriendly'])) {
            return;
        }

        try {
            $repository = AiFactory::repository();
            $key = $this->contentKey((int) $article->id, (string) ($article->language ?? ''));
            $exclude = !empty($form['aifriendly']['exclude']);

            if ($repository->isExcluded($key) === $exclude) {
                return;
            }

            $repository->saveState($key, ['excluded' => $exclude ? 1 : 0]);
            $repository->addEvent('content_exclusion_toggle', ['key' => $key, 'excluded' => $exclude, 'source' => 'editor']);
        } catch (\Throwable) {
            // Never block saving an article over our own bookkeeping.
        }
    }

    /**
     * Mirrors ContentItem::key() for Joomla articles.
     */
    private function contentKey(int $id, string $language): string
    {
        return 'joomla:article:' . $id . ':' . ($language !== '' ? $language : '*');
    }
}
