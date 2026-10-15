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
    public static function parse(string $plainText, ?int $yearHint = null): ?array
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

        if (preg_match('/ore\s*(\d{1,2})[:.](\d{2})/iu', $window, $time)
            || preg_match('/ore\s*(\d{1,2})[:.](\d{2})/iu', $text, $time)) {
            $start .= sprintf('T%02d:%02d', (int) $time[1], (int) $time[2]);
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

        return ['start' => $start, 'end' => $end];
    }
}
