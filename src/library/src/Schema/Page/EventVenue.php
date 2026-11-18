<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Schema\Page;

defined('_JEXEC') or die;

/**
 * Where an event takes place, and how it is attended.
 *
 * The school is the default venue of every event, and for a concert at a
 * theatre across town that is the wrong address. The venue is therefore read,
 * most specific first, from:
 *
 *  1. Custom Fields of the article, by conventional name:
 *       `evento_luogo`      venue name
 *       `evento_indirizzo`  street | postal code | city
 *       `evento_url`        venue website or map link
 *       `evento_sameas`     one URL per line (Wikipedia, Wikidata, ...)
 *       `evento_online`     address of the live stream: the event is online
 *       `evento_modalita`   online | mixed | offline, when it is not obvious
 *       `evento_stato`      scheduled | cancelled | postponed | rescheduled | online
 *  2. The table of recurring venues kept in the Hub, matched by a keyword in
 *     the title and then in the text of the page, one venue per line:
 *       `keyword ; other keyword | name | street | postal code | city | url`
 *     A venue named `@online` stands for a live stream, with its address in the
 *     last column.
 *  3. Nothing: the caller keeps the school as the venue.
 *
 * Pure PHP, covered by unit tests.
 */
final class EventVenue
{
    private const STATUS = [
        'scheduled' => 'EventScheduled', 'programmato' => 'EventScheduled', 'confermato' => 'EventScheduled',
        'cancelled' => 'EventCancelled', 'canceled' => 'EventCancelled', 'annullato' => 'EventCancelled', 'cancellato' => 'EventCancelled',
        'postponed' => 'EventPostponed', 'rinviato' => 'EventPostponed', 'posticipato' => 'EventPostponed',
        'rescheduled' => 'EventRescheduled', 'riprogrammato' => 'EventRescheduled',
        'online' => 'EventMovedOnline', 'movedonline' => 'EventMovedOnline', 'spostatoonline' => 'EventMovedOnline',
    ];

    private const MODES = [
        'offline' => 'offline', 'inpresenza' => 'offline', 'presenza' => 'offline',
        'online' => 'online', 'virtuale' => 'online',
        'mixed' => 'mixed', 'ibrido' => 'mixed', 'misto' => 'mixed',
    ];

    /** Hosts whose links point at a map rather than at the venue's own site. */
    private const MAP_HOSTS = '#^https?://(?:[a-z0-9-]+\.)*(?:google\.[a-z.]+/maps|maps\.google\.|goo\.gl/maps|maps\.app\.goo\.gl|openstreetmap\.org|maps\.apple\.com)#i';

    /**
     * Parses the table of recurring venues.
     *
     * @return array<int, array{keywords:string[], name:string, street:string, postal:string, city:string, url:string}>
     */
    public static function rules(string $text): array
    {
        $rules = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$keyword, $name, $street, $postal, $city, $url] = array_pad(array_map('trim', explode('|', $line, 6)), 6, '');
            $keywords = array_values(array_filter(array_map('trim', explode(';', $keyword)), static fn (string $k): bool => $k !== ''));

            if ($keywords === [] || $name === '') {
                continue;
            }

            $rules[] = compact('keywords', 'name', 'street', 'postal', 'city', 'url');
        }

        return $rules;
    }

    /**
     * @param array<string, string> $fields Custom fields of the article by name.
     * @param array<int, array{keywords:string[], name:string, street:string, postal:string, city:string, url:string}> $rules
     * @return array{place:?array{name:string, street:string, postal:string, city:string, url:string, sameAs:string[]}, online:?string, mode:?string}
     *         `place` is null when nothing says where the event is, so the school stays its venue;
     *         `online` is the address of the stream; `mode` is offline, online or mixed, or null when unstated.
     */
    public static function resolve(array $fields, array $rules, string $title, string $plain, string $pageUrl): array
    {
        $place = null;
        $online = null;

        $name = self::field($fields, 'evento_luogo');

        if ($name !== '') {
            [$street, $postal, $city] = array_pad(array_map('trim', explode('|', self::field($fields, 'evento_indirizzo'), 3)), 3, '');
            $place = ['name' => $name, 'street' => $street, 'postal' => $postal, 'city' => $city, 'url' => '', 'sameAs' => []];
        } else {
            foreach ([$title, mb_substr($plain, 0, 1500)] as $haystack) {
                foreach ($rules as $rule) {
                    foreach ($rule['keywords'] as $keyword) {
                        if (mb_stripos($haystack, $keyword) === false) {
                            continue;
                        }

                        if (preg_match('/^@?(?:online|virtuale)$/iu', $rule['name'])) {
                            $online ??= self::url($rule['url']) ?: $pageUrl;
                            continue 3;
                        }

                        $place = ['name' => $rule['name'], 'street' => $rule['street'], 'postal' => $rule['postal'], 'city' => $rule['city'], 'url' => '', 'sameAs' => []];
                        $link = self::url($rule['url']);
                        break 3;
                    }
                }
            }
        }

        if ($place !== null) {
            $link ??= '';
            $link = self::url(self::field($fields, 'evento_url')) ?: $link;

            if ($link !== '') {
                if (preg_match(self::MAP_HOSTS, $link)) {
                    $place['hasMap'] = $link;
                } else {
                    $place['url'] = $link;
                }
            }

            $place['sameAs'] = array_values(array_filter(array_map([self::class, 'url'], preg_split('/\R/u', self::field($fields, 'evento_sameas')) ?: [])));
        }

        $stream = self::field($fields, 'evento_online');

        if ($stream !== '') {
            $online = self::url($stream) ?: ($online ?? $pageUrl);
        }

        $mode = self::MODES[self::slug(self::field($fields, 'evento_modalita'))] ?? null;

        if ($mode === null && $online !== null) {
            // A stream and a venue stated for the same event: both are attended.
            $mode = $place !== null ? 'mixed' : 'online';
        }

        return ['place' => $place, 'online' => $online, 'mode' => $mode];
    }

    /** schema.org URL of the event's status. */
    public static function status(array $fields, string $title): string
    {
        $declared = self::STATUS[self::slug(self::field($fields, 'evento_stato'))] ?? null;

        if ($declared === null) {
            // Only the title is read: the text of a page may well mention a
            // cancelled date of another year.
            if (preg_match('/\b(?:annullat[oa]|cancellat[oa]|cancelled|canceled)\b/iu', $title)) {
                $declared = 'EventCancelled';
            } elseif (preg_match('/\b(?:rinviat[oa]|posticipat[oa]|postponed)\b/iu', $title)) {
                $declared = 'EventPostponed';
            }
        }

        return 'https://schema.org/' . ($declared ?? 'EventScheduled');
    }

    /**
     * schema.org URL of the attendance mode.
     *
     * @param ?string $mode offline, online, mixed, or null for the default (offline).
     */
    public static function attendanceMode(?string $mode): string
    {
        return 'https://schema.org/' . match ($mode) {
            'online' => 'OnlineEventAttendanceMode',
            'mixed' => 'MixedEventAttendanceMode',
            default => 'OfflineEventAttendanceMode',
        };
    }

    /**
     * The `location` value of an event.
     *
     * @param array{place:?array, online:?string, mode:?string} $venue
     * @param array<string, mixed> $schoolPlace The default location, used when the event states no venue.
     * @param array<string, mixed> $org         The organisation node, to borrow the country from.
     * @return array<int|string, mixed>|null
     */
    public static function location(array $venue, ?array $schoolPlace, array $org = []): ?array
    {
        $nodes = [];

        if ($venue['place'] !== null) {
            $nodes[] = self::placeNode($venue['place'], $org, $schoolPlace);
        } elseif ($schoolPlace !== null && $venue['mode'] !== 'online') {
            $nodes[] = $schoolPlace;
        }

        if ($venue['online'] !== null) {
            $nodes[] = ['@type' => 'VirtualLocation', 'url' => $venue['online']];
        }

        if ($nodes === []) {
            return $schoolPlace;
        }

        return count($nodes) === 1 ? $nodes[0] : $nodes;
    }

    /**
     * A Place with name, postal address and, when given, links.
     *
     * A venue that is the school itself points at the school's own Place
     * instead of repeating it under another identity.
     *
     * @param array{name:string, street:string, postal:string, city:string, url:string, sameAs:string[], hasMap?:string} $place
     */
    public static function placeNode(array $place, array $org = [], ?array $schoolPlace = null): array
    {
        $school = (array) ($org['address'] ?? []);
        $street = mb_strtolower(trim((string) ($school['streetAddress'] ?? '')));

        if ($schoolPlace !== null && !empty($schoolPlace['@id'])
            && (mb_strtolower($place['name']) === mb_strtolower((string) ($org['name'] ?? '')) || ($street !== '' && mb_strtolower($place['street']) === $street))) {
            return ['@id' => $schoolPlace['@id']];
        }

        $address = [
            '@type' => 'PostalAddress',
            'streetAddress' => $place['street'],
            'postalCode' => $place['postal'],
            'addressLocality' => $place['city'],
        ];

        // The country is borrowed from the school only when the venue is in the
        // same city, or says nothing about where it is.
        $sameCity = $place['city'] === '' || mb_strtolower($place['city']) === mb_strtolower((string) ($school['addressLocality'] ?? ''));

        if ($sameCity && !empty($school['addressCountry']) && ($place['street'] !== '' || $place['postal'] !== '' || $place['city'] !== '')) {
            $address['addressCountry'] = $school['addressCountry'];
        }

        $address = array_filter($address, static fn ($v): bool => $v !== '');

        $node = ['@type' => 'Place', 'name' => $place['name']];

        if (count($address) > 1) {
            $node['address'] = $address;
        }

        foreach (['url', 'hasMap'] as $key) {
            if (!empty($place[$key])) {
                $node[$key] = $place[$key];
            }
        }

        if (!empty($place['sameAs'])) {
            $node['sameAs'] = $place['sameAs'];
        }

        return $node;
    }

    private static function field(array $fields, string $name): string
    {
        $value = $fields[$name] ?? '';

        return trim(is_scalar($value) ? (string) $value : '');
    }

    /** The value when it is an http(s) address, an empty string otherwise. */
    private static function url(string $value): string
    {
        $value = trim($value);

        return preg_match('#^https?://[^\s]+$#i', $value) && filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
    }

    /** Lower-case letters only, so "In presenza" and "in_presenza" read the same. */
    private static function slug(string $value): string
    {
        return (string) preg_replace('/[^a-z]+/', '', mb_strtolower($value));
    }
}
