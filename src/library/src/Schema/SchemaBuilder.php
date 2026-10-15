<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Schema;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Sernicola\Library\AiFriendly\Dto\ContentItem;
use Sernicola\Library\AiFriendly\Support\EventNames;
use Sernicola\Library\AiFriendly\Support\Lang;

final class SchemaBuilder
{
    public function __construct(private readonly array $options)
    {
    }

    public function graph(?ContentItem $item = null): array
    {
        if (empty($this->options['schema_enabled'])) {
            return [];
        }
        $home = rtrim(Uri::root(), '/') . '/';
        $identityId = $home . '#identity';
        $websiteId = $home . '#website';
        $identityType = in_array($this->options['schema_entity_type'] ?? '', ['Person', 'Organization'], true) ? $this->options['schema_entity_type'] : 'Person';
        $identity = [
            '@type' => $identityType,
            '@id' => $identityId,
            'name' => $this->options['schema_name'] ?: Factory::getApplication()->getConfig()->get('sitename'),
            'url' => $this->options['schema_creator_url'] ?: $home,
            'alternateName' => $this->options['schema_alternate_name'] ?? '',
            'description' => $this->options['schema_description'] ?? '',
            'disambiguatingDescription' => $this->options['schema_disambiguating_description'] ?? '',
            'sameAs' => $this->lines('schema_same_as', true),
            'knowsAbout' => $this->lines('schema_knows_about'),
            'knowsLanguage' => $this->lines('schema_knows_language'),
            'image' => ($this->options['schema_image_url'] ?? '') ? ['@type' => 'ImageObject', '@id' => $home . '#identity-image', 'url' => $this->options['schema_image_url']] : null,
        ];
        if ($identityType === 'Person') {
            $identity['jobTitle'] = $this->options['schema_job_title'] ?? '';
        } else {
            // The creator URL names whoever built the site, not the organization.
            $identity['url'] = $home;
            $extraTypes = $this->lines('schema_extra_types');
            if ($extraTypes) {
                $identity['@type'] = array_values(array_unique(array_merge($extraTypes, [$identityType])));
            }
            $identity = array_merge($identity, [
                'additionalType' => $this->options['schema_additional_type'] ?? '',
                'slogan' => $this->options['schema_slogan'] ?? '',
                'foundingDate' => $this->options['schema_founding_date'] ?? '',
                'legalName' => $this->options['schema_legal_name'] ?? '',
                'vatID' => $this->options['schema_vat_id'] ?? '',
                'taxID' => $this->options['schema_tax_id'] ?? '',
                'leiCode' => $this->options['schema_lei_code'] ?? '',
                'tickerSymbol' => $this->options['schema_ticker_symbol'] ?? '',
                'areaServed' => $this->lines('schema_area_served'),
                'logo' => ($this->options['schema_logo_url'] ?? '') ? ['@type' => 'ImageObject', '@id' => $home . '#logo', 'url' => $this->options['schema_logo_url']] : null,
                'address' => $this->address(),
                'contactPoint' => $this->contact(),
                'founder' => $this->founders(),
                'telephone' => trim((string) ($this->options['schema_telephone'] ?? '')),
                'priceRange' => trim((string) ($this->options['schema_price_range'] ?? '')),
                'openingHoursSpecification' => $this->openingHours(),
                'geo' => $this->geo(),
                'hasMap' => trim((string) ($this->options['schema_has_map'] ?? '')),
            ]);
        }
        $creatorType = in_array($this->options['schema_creator_type'] ?? '', ['Person', 'Organization'], true) ? $this->options['schema_creator_type'] : 'Organization';
        $creatorName = trim((string) ($this->options['schema_creator_name'] ?? ''));
        $website = [
            '@type' => 'WebSite', '@id' => $websiteId, 'url' => $home,
            'name' => Factory::getApplication()->getConfig()->get('sitename'),
            'publisher' => ['@id' => $identityId],
            'creator' => $creatorName !== '' ? ['@type' => $creatorType, 'name' => $creatorName, 'url' => $this->options['schema_creator_url'] ?? ''] : null,
            'potentialAction' => $this->searchAction(),
        ];
        $graph = [$website, $identity];
        if ($catalog = $this->offerCatalog($identityId, $home)) {
            $graph[] = $catalog;
            if ($identityType !== 'Person') {
                $graph[1]['hasOfferCatalog'] = ['@id' => $catalog['@id']];
            }
        }
        if (($profileUrl = trim((string) ($this->options['schema_profile_page_url'] ?? ''))) !== '') {
            $graph[] = ['@type' => 'ProfilePage', '@id' => $profileUrl . '#profile', 'url' => $profileUrl, 'mainEntity' => ['@id' => $identityId]];
        }
        if ($item) {
            $graph[] = [
                '@type' => $item->type === 'article' ? 'Article' : 'WebPage',
                '@id' => $item->canonicalUrl . '#webpage',
                'url' => $item->canonicalUrl,
                'name' => $item->title,
                'description' => $item->metadata['description'] ?? $item->excerpt,
                'isPartOf' => ['@id' => $websiteId],
                'about' => ['@id' => $identityId],
                'license' => $this->options['schema_license'] ?? '',
                'datePublished' => $item->metadata['date'] ?? '',
                'dateModified' => $item->metadata['modified'] ?? '',
            ];
        }
        $graph = $this->clean($graph);
        $event = new Event(EventNames::BUILD_SCHEMA, ['graph' => $graph, 'item' => $item, 'options' => $this->options]);
        Factory::getApplication()->getDispatcher()->dispatch(EventNames::BUILD_SCHEMA, $event);

        return array_values((array) $event->getArgument('graph', $graph));
    }

    public function jsonLd(?ContentItem $item = null): string
    {
        $graph = $this->graph($item);
        return $graph ? (string) json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : '';
    }

    public function merge(array $base, array $additions): array
    {
        $indexed = [];
        foreach ($base as $index => $node) {
            if (is_array($node) && !empty($node['@id'])) {
                $indexed[$node['@id']] = $index;
            }
        }
        foreach ($additions as $node) {
            $id = is_array($node) ? ($node['@id'] ?? '') : '';
            if ($id !== '' && isset($indexed[$id])) {
                $base[$indexed[$id]] = $this->mergeNode($base[$indexed[$id]], $node);
            } else {
                $base[] = $node;
            }
        }

        return $this->clean($base);
    }

    private function mergeNode(array $base, array $addition): array
    {
        foreach ($addition as $key => $value) {
            if (!array_key_exists($key, $base) || $base[$key] === '' || $base[$key] === []) {
                $base[$key] = $value;
            } elseif (is_array($base[$key]) && is_array($value) && !isset($value['@id'])) {
                $base[$key] = array_is_list($base[$key]) ? array_values(array_unique(array_merge($base[$key], $value), SORT_REGULAR)) : $this->mergeNode($base[$key], $value);
            }
        }
        return $base;
    }

    private function lines(string $key, bool $urls = false): array
    {
        $lines = preg_split('/\R/u', (string) ($this->options[$key] ?? '')) ?: [];
        $values = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== '' && (!$urls || filter_var($line, FILTER_VALIDATE_URL))));
        if ($urls) {
            $seen = [];
            $values = array_values(array_filter($values, static function (string $url) use (&$seen): bool {
                $key = strtolower(rtrim($url, '/'));
                if (isset($seen[$key])) return false;
                return $seen[$key] = true;
            }));
        }
        return $values;
    }

    private function address(): ?array
    {
        $node = ['@type' => 'PostalAddress', 'streetAddress' => $this->options['schema_street_address'] ?? '', 'postalCode' => $this->options['schema_postal_code'] ?? '', 'addressLocality' => $this->options['schema_address_locality'] ?? '', 'addressRegion' => $this->options['schema_address_region'] ?? '', 'addressCountry' => $this->options['schema_address_country'] ?? ''];
        return count(array_filter($node, static fn ($v, $k): bool => $k === '@type' || $v !== '', ARRAY_FILTER_USE_BOTH)) > 1 ? $node : null;
    }

    private function contact(): ?array
    {
        $email = trim((string) ($this->options['schema_contact_email'] ?? ''));
        $type = trim((string) ($this->options['schema_contact_type'] ?? ''));
        return $email !== '' || $type !== '' ? ['@type' => 'ContactPoint', 'contactType' => $type, 'email' => $email, 'availableLanguage' => $this->lines('schema_contact_languages')] : null;
    }

    private function founders(): array
    {
        $founders = [];
        foreach ($this->lines('schema_founders') as $line) {
            [$name, $role] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if ($name !== '') $founders[] = array_filter(['@type' => 'Person', 'name' => $name, 'jobTitle' => $role]);
        }
        return $founders;
    }

    /**
     * One line per group of days: "Mo-Th 10:30-22:00", "Fr 10:30-21:00", "Sa 10:00-16:00".
     * Days not listed are not published, which reads as closed.
     */
    private function openingHours(): array
    {
        $order = ['mo' => 'Monday', 'tu' => 'Tuesday', 'we' => 'Wednesday', 'th' => 'Thursday', 'fr' => 'Friday', 'sa' => 'Saturday', 'su' => 'Sunday'];
        $keys = array_keys($order);
        $specs = [];
        foreach ($this->lines('schema_opening_hours') as $line) {
            if (!preg_match('/^([A-Za-z]{2})(?:\s*-\s*([A-Za-z]{2}))?\s+(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', $line, $m)) {
                continue;
            }
            $from = array_search(strtolower($m[1]), $keys, true);
            $to = $m[2] !== '' ? array_search(strtolower($m[2]), $keys, true) : $from;
            if ($from === false || $to === false || $to < $from) {
                continue;
            }
            $days = [];
            for ($i = $from; $i <= $to; $i++) {
                $days[] = $order[$keys[$i]];
            }
            $specs[] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => count($days) === 1 ? $days[0] : $days,
                'opens' => sprintf('%02d:%s', (int) explode(':', $m[3])[0], explode(':', $m[3])[1]),
                'closes' => sprintf('%02d:%s', (int) explode(':', $m[4])[0], explode(':', $m[4])[1]),
            ];
        }

        return $specs;
    }

    private function geo(): ?array
    {
        $lat = trim((string) ($this->options['schema_geo_latitude'] ?? ''));
        $lng = trim((string) ($this->options['schema_geo_longitude'] ?? ''));
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        return ['@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng];
    }

    /** The template must contain {search_term_string}, otherwise it is not a search target. */
    private function searchAction(): ?array
    {
        $target = trim((string) ($this->options['schema_search_url'] ?? ''));
        if ($target === '' || !str_contains($target, '{search_term_string}')) {
            return null;
        }

        return ['@type' => 'SearchAction', 'target' => $target, 'query-input' => 'required name=search_term_string'];
    }

    private function offerCatalog(string $identityId, string $home): ?array
    {
        $services = $this->options['schema_services'] ?? [];
        if (is_string($services)) $services = json_decode($services, true) ?: [];
        $offers = [];
        foreach ((array) $services as $service) {
            if (!is_array($service) || trim((string) ($service['name'] ?? '')) === '') continue;
            $offers[] = ['@type' => 'Offer', 'itemOffered' => array_filter(['@type' => 'Service', 'name' => trim((string) $service['name']), 'description' => trim((string) ($service['description'] ?? '')), 'url' => trim((string) ($service['url'] ?? '')), 'provider' => ['@id' => $identityId]])];
        }
        return $offers ? ['@type' => 'OfferCatalog', '@id' => $home . '#services', 'name' => Lang::text('PLG_SYSTEM_AIFRIENDLY_SCHEMA_SERVICES', 'Services'), 'itemListElement' => $offers] : null;
    }

    private function clean($value)
    {
        if (!is_array($value)) return $value;
        $clean = [];
        foreach ($value as $key => $item) {
            $item = $this->clean($item);
            if ($item === '' || $item === null || $item === []) continue;
            $clean[$key] = $item;
        }
        return array_is_list($value) ? array_values($clean) : $clean;
    }
}

