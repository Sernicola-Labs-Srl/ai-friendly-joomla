<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Sernicola\Library\AiFriendly\Integration\ForSeo;
use Sernicola\Library\AiFriendly\Rule\RuleEngine;
use Sernicola\Library\AiFriendly\Schema\OutputMode;
use Sernicola\Library\AiFriendly\Storage\Repository;
use Sernicola\Library\AiFriendly\Storage\StaticVersionStore;

final class Diagnostics
{
    public function __construct(private readonly ContentRegistry $registry, private readonly RuleEngine $rules, private readonly StaticVersionStore $store, private readonly Repository $repository, private readonly array $options)
    {
    }

    public function run(): array
    {
        $warnings = [];
        $errors = [];
        $included = 0;
        foreach ($this->registry->all([], 200) as $item) {
            if ($this->rules->allows($item, 'diagnostic')) $included++;
        }
        if ($included === 0) $warnings[] = ['code' => 'empty_scope', 'level' => 'warning', 'message' => 'Zero contenuti inclusi con le regole correnti.'];
        $static = $this->store->stats();
        if (!empty($this->options['static_md_files']) && !$static['writable']) $errors[] = ['code' => 'static_not_writable', 'level' => 'error', 'message' => 'La directory dei Markdown statici non è scrivibile.'];
        if (empty($this->options['auto_regenerate']) || empty($this->options['static_md_files'])) $warnings[] = ['code' => 'automation_disabled', 'level' => 'info', 'message' => 'Rigenerazione automatica o file statici non attivi.'];
        if (!empty($this->options['schema_enabled']) && trim((string) ($this->options['schema_name'] ?? '')) === '') $warnings[] = ['code' => 'schema_missing_name', 'level' => 'warning', 'message' => 'Semantic Schema attivo senza nome entità; verrà usato il nome del sito.'];
        if (!empty($this->options['schema_enabled']) && OutputMode::resolve($this->options) === OutputMode::FORSEO && !ForSeo::bridgeRegistered()) {
            $errors[] = ['code' => 'forseo_bridge_missing', 'level' => 'error', 'message' => 'Modalità 4SEO attiva ma il bridge non è registrato nel functions file di 4SEO.'];
        }
        // Informational only: which structured-data producers run is a site
        // configuration choice. It is surfaced because it changes what this
        // extension enriches, not as a recommendation.
        if (!empty($this->options['schema_enabled']) && OutputMode::forSeoAvailable() && OutputMode::coreAvailable()) {
            $warnings[] = ['code' => 'schema_two_producers', 'level' => 'info', 'message' => 'Sono attivi due produttori di dati strutturati: AI Friendly arricchisce solo quello selezionato.'];
        }
        $patterns = array_filter(array_map('trim', preg_split('/\R/u', (string) ($this->options['exclude_url_patterns'] ?? '')) ?: []));
        if (count($patterns) !== count(array_unique($patterns))) $warnings[] = ['code' => 'duplicate_patterns', 'level' => 'warning', 'message' => 'Sono presenti pattern URL duplicati.'];
        $config = Factory::getApplication()->getConfig();
        if ((int) $config->get('robots', 1) === 0) $warnings[] = ['code' => 'robots_disabled', 'level' => 'warning', 'message' => 'La configurazione globale scoraggia i crawler.'];

        // No content type enabled is a different failure from "the rules match
        // nothing": here the extension cannot expose anything at all.
        if (!array_filter([$this->options['include_articles'] ?? 0, $this->options['include_categories'] ?? 0, $this->options['include_contacts'] ?? 0, $this->options['include_tags'] ?? 0])) {
            $errors[] = ['code' => 'no_content_type', 'level' => 'error', 'message' => 'Nessun tipo di contenuto abilitato nelle regole.'];
        }

        if ((int) $config->get('sef', 1) === 0) {
            $warnings[] = ['code' => 'sef_disabled', 'level' => 'warning', 'message' => 'Gli URL SEF sono disattivati: gli endpoint .md potrebbero non risolvere.'];
        }

        if (!empty($this->options['schema_enabled']) && trim((string) ($this->options['schema_same_as'] ?? '')) === '') {
            $warnings[] = ['code' => 'schema_missing_sameas', 'level' => 'info', 'message' => 'Semantic Schema senza profili sameAs: l entita resta piu difficile da riconciliare.'];
        }

        if (!empty($this->options['notify_email']) && !filter_var((string) ($this->options['notify_email_to'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $warnings[] = ['code' => 'notify_email_invalid', 'level' => 'warning', 'message' => 'Notifiche email attive senza un indirizzo valido.'];
        }

        if (!empty($this->options['static_md_files']) && $static['writable'] && (int) $static['count'] === 0) {
            $warnings[] = ['code' => 'static_never_generated', 'level' => 'info', 'message' => 'File statici attivi ma nessun file ancora generato.'];
        }

        if ($failures = $this->lastRunErrors()) {
            $warnings[] = ['code' => 'last_run_errors', 'level' => 'warning', 'message' => 'L ultima rigenerazione ha riportato ' . $failures . ' errori.'];
        }

        if ((!empty($this->options['auto_regenerate']) || !empty($this->options['llms_full_enabled'])) && $this->scheduledTaskMissing()) {
            $warnings[] = ['code' => 'task_not_scheduled', 'level' => 'warning', 'message' => 'Task pianificato abilitato ma non configurato in Attivita pianificate.'];
        }

        return [
            'warnings' => $warnings, 'errors' => $errors, 'included_count' => $included,
            'total_count' => $this->registry->count(), 'static' => $static,
            'llms_full' => FullCorpus::state(!empty($this->options['llms_full_enabled']), (string) ($this->options['llms_full_hash'] ?? '')),
            'llms_url' => rtrim(Uri::root(), '/') . '/llms.txt',
            'sitemap_url' => rtrim(Uri::root(), '/') . '/sitemap.xml',
            'events' => $this->repository->events(10),
        ];
    }

    /**
     * Error count of the most recent regeneration, read back from the event log.
     */
    private function lastRunErrors(): int
    {
        foreach ($this->repository->events(50) as $event) {
            if (($event['event_type'] ?? '') === 'regeneration') {
                return (int) ($event['payload']['stats']['errors'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * True when no scheduled task runs our routine.
     *
     * Enabling the option alone changes nothing: the task itself has to exist
     * in com_scheduler, and that is easy to miss.
     */
    private function scheduledTaskMissing(): bool
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__scheduler_tasks'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('aifriendly.regenerate'))
                ->where($db->quoteName('state') . ' = 1');

            return (int) $db->setQuery($query)->loadResult() === 0;
        } catch (\Throwable) {
            // No scheduler table, or no access: stay quiet rather than cry wolf.
            return false;
        }
    }
}

