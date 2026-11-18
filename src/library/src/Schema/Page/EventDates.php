<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Schema\Page;

defined('_JEXEC') or die;

/**
 * Reads the date of an event out of free Italian text.
 *
 * The pages of the site announce events in prose ("Sabato 11 aprile 2026 ore
 * 18:15"). A date is only returned when it can be read without guessing: the
 * year must be in the text or in a hint given by the caller (the title of the
 * category, "Iniziative 2026"), and anything else yields null so no event is
 * published with an invented date.
 */
final class EventDates
{
    private const MONTHS = [
        'gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4, 'maggio' => 5, 'giugno' => 6,
        'luglio' => 7, 'agosto' => 8, 'settembre' => 9, 'ottobre' => 10, 'novembre' => 11, 'dicembre' => 12,
    ];

    /**
     * @return array{start:string, end:?string}|null Dates as ISO strings; start carries the time when the text gives one.
     */
    public static function parse(string $plainText, ?int $yearHint = null, string $timezone = 'Europe/Rome'): ?array
    {
        $text = mb_substr($plainText, 0, 3000);
        $months = implode('|', array_keys(self::MONTHS));

        if (!preg_match_all('/(\d{1,2})\s*(?:°|º)?\s+(' . $months . ')(?:\s+(\d{4}))?/iu', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $dates = [];

        foreach ($matches as $match) {
            $day = (int) $match[1][0];
            $month = self::MONTHS[mb_strtolower($match[2][0])];
            $year = isset($match[3][0]) && $match[3][0] !== '' ? (int) $match[3][0] : $yearHint;

            if ($year === null || !checkdate($month, $day, $year)) {
                continue;
            }

            $dates[] = ['iso' => sprintf('%04d-%02d-%02d', $year, $month, $day), 'offset' => $match[0][1], 'length' => strlen($match[0][0])];
        }

        if (!$dates) {
            return null;
        }

        $first = $dates[0];
        $start = $first['iso'];
        $window = substr($text, $first['offset'] + $first['length'], 60);

        $zone = self::zone($timezone);
        $startTime = null;
        $endTime = null;

        if (preg_match('/ore\s*(\d{1,2})[:.](\d{2})/iu', $window, $time)
            || preg_match('/ore\s*(\d{1,2})[:.](\d{2})/iu', $text, $time)) {
            $startTime = [(int) $time[1], (int) $time[2]];
        }

        // "18:15-20:00", "dalle 18 alle 20": the range says when it ends. A range
        // far from the date is taken only when it starts at the time already read,
        // so that a second time in the text ("ore 18:15 e ore 21:00") is not an end.
        $range = self::timeRange($window);

        if ($range === null && ($far = self::timeRange($text)) !== null && ($startTime === null || $startTime === [$far[0], $far[1]])) {
            $range = $far;
        }

        if ($range !== null) {
            $startTime = [$range[0], $range[1]];
            $endTime = [$range[2], $range[3]];
        }

        $endDateTime = null;

        if ($startTime !== null) {
            try {
                $date = new \DateTimeImmutable($start . sprintf(' %02d:%02d', $startTime[0], $startTime[1]), $zone);
                $start = $date->format('Y-m-d\TH:i:sP');

                if ($endTime !== null) {
                    $finish = $date->setTime($endTime[0], $endTime[1]);
                    // A show that ends after midnight ends the next day.
                    $endDateTime = ($finish <= $date ? $finish->modify('+1 day') : $finish)->format('Y-m-d\TH:i:sP');
                }
            } catch (\Exception) {
                // Keep the date-only value when the time itself is invalid.
            }
        }

        $end = null;

        foreach (array_slice($dates, 1) as $candidate) {
            $between = substr($text, $first['offset'] + $first['length'], $candidate['offset'] - $first['offset'] - $first['length']);

            // "dal 2 al 5 luglio", "dal 2 luglio a domenica 5 luglio": a short
            // connective and nothing else between the two dates.
            if (strlen($between) <= 25 && preg_match('/\b(al|a|fino|-|–)\b|[-–]/iu', $between)
                && $candidate['iso'] >= $first['iso'] && (strtotime($candidate['iso']) - strtotime($first['iso'])) <= 14 * 86400) {
                $end = $candidate['iso'];
            }

            break;
        }

        // Several days win over a time range: the end is a date.
        return ['start' => $start, 'end' => $end ?? $endDateTime];
    }

    /**
     * The end of an event as written in a custom field.
     *
     * Accepts `2026-04-11 20:00[:00]`, `2026-04-11`, `11/04/2026 20:00` and a
     * bare time (`20:00`, the same day as $startIso). The value is read in the
     * site's time zone.
     */
    public static function fromField(string $value, string $startIso, string $timezone = 'Europe/Rome'): ?string
    {
        $value = trim($value);
        $zone = self::zone($timezone);

        try {
            if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $value, $m) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $startIso, $d)) {
                return (new \DateTimeImmutable($d[1] . sprintf(' %02d:%02d', (int) $m[1], (int) $m[2]), $zone))->format('Y-m-d\TH:i:sP');
            }

            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2})[:.](\d{2}))?$/', $value, $m)) {
                if (!checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                    return null;
                }
                $iso = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);

                return isset($m[4]) && $m[4] !== ''
                    ? (new \DateTimeImmutable($iso . sprintf(' %02d:%02d', (int) $m[4], (int) $m[5]), $zone))->format('Y-m-d\TH:i:sP')
                    : $iso;
            }

            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2})?)?$/', $value, $m)) {
                if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return null;
                }
                $iso = $m[1] . '-' . $m[2] . '-' . $m[3];

                return isset($m[4]) && $m[4] !== ''
                    ? (new \DateTimeImmutable($iso . ' ' . $m[4] . ':' . $m[5], $zone))->format('Y-m-d\TH:i:sP')
                    : $iso;
            }
        } catch (\Exception) {
            return null;
        }

        return null;
    }

    /**
     * @return array{0:int, 1:int, 2:int, 3:int}|null Start hour and minute, end hour and minute.
     */
    private static function timeRange(string $text): ?array
    {
        $patterns = [
            // 18:15-20:00, 18.15 – 20.00, 18:15 alle 20:00
            '/(\d{1,2})[:.](\d{2})\s*(?:-|–|—|\balle\b(?:\s+ore)?|\ba\b)\s*(?:ore\s*)?(\d{1,2})[:.](\d{2})/iu',
            // dalle 18 alle 20, dalle ore 18:15 alle 19:30
            '/\bdalle(?:\s+ore)?\s*(\d{1,2})(?:[:.](\d{2}))?\s*(?:\balle\b|\ba\b|-|–)\s*(?:ore\s*)?(\d{1,2})(?:[:.](\d{2}))?/iu',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $text, $m)) {
                continue;
            }

            $range = [(int) $m[1], (int) ($m[2] ?? 0), (int) $m[3], (int) ($m[4] ?? 0)];

            if ($range[0] <= 23 && $range[2] <= 23 && $range[1] <= 59 && $range[3] <= 59) {
                return $range;
            }
        }

        return null;
    }

    private static function zone(string $timezone): \DateTimeZone
    {
        try {
            return new \DateTimeZone($timezone);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
