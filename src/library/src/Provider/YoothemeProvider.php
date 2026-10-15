<?php

/**
 * @package     AI Friendly for Joomla
 * @copyright   Copyright (C) 2026 Sernicola Labs. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Sernicola\Library\AiFriendly\Provider;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Sernicola\Library\AiFriendly\Contract\ContentProviderInterface;
use Sernicola\Library\AiFriendly\Dto\ContentItem;
use Sernicola\Library\AiFriendly\Support\Path;

final class YoothemeProvider implements ContentProviderInterface
{
    /** Builder elements that carry no reading material. */
    private const SKIP_TYPES = ['image', 'video', 'map', 'social', 'pagination', 'search'];

    /** Fields whose value is already HTML written in the editor. */
    private const HTML_FIELDS = ['content', 'text', 'description', 'meta', 'caption'];

    /** Fields that hold a plain-text heading. */
    private const HEADING_FIELDS = ['title', 'headline'];

    /** Fields that hold a short plain-text label. */
    private const TEXT_FIELDS = ['label'];

    /** @var array<string, mixed>|null Layouts resolved once per instance. */
    private ?array $pages = null;

    public function name(): string
    {
        return 'yootheme';
    }

    public function list(array $filters = [], int $limit = 100, int $offset = 0): iterable
    {
        foreach (array_slice($this->pages(), max(0, $offset), max(1, $limit), true) as $route => $layout) {
            $fragments = $this->collect($layout);

            if (!$fragments) {
                continue;
            }

            $html = $this->toHtml($fragments);
            $plain = $this->toPlainText($fragments);
            $url = rtrim(Uri::root(), '/') . Path::normalize($route);

            yield new ContentItem(
                'yootheme',
                'page',
                hash('sha256', $route),
                '*',
                $this->title($fragments, $route),
                $url,
                Path::normalize($route),
                $html,
                $this->excerpt($plain),
                ['description' => $this->excerpt($plain)],
                [],
                '',
                true,
                true
            );
        }
    }

    public function resolve(string $path, ?string $language = null): ?ContentItem
    {
        $path = Path::normalize($path);

        foreach ($this->list([], count($this->pages()) ?: 1) as $item) {
            if (Path::normalize($item->routePath) === $path) {
                return $item;
            }
        }

        return null;
    }

    public function find(string $id, ?string $language = null): ?ContentItem
    {
        foreach ($this->list([], count($this->pages()) ?: 1) as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    public function count(array $filters = []): int
    {
        return count($this->pages());
    }

    /**
     * @return array<string, mixed>
     */
    private function pages(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }

        $this->pages = [];

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select('params')
                ->from($db->quoteName('#__template_styles'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('template') . ' = ' . $db->quote('yootheme'))
                ->order($db->quoteName('home') . ' DESC');
            $params = json_decode((string) $db->setQuery($query, 0, 1)->loadResult(), true) ?: [];

            foreach (['pages', 'layouts'] as $key) {
                if (!empty($params[$key]) && is_array($params[$key])) {
                    $this->pages = array_filter(
                        $params[$key],
                        static fn ($value, $route): bool => is_string($route) && !str_starts_with($route, 'com_') && (is_array($value) || is_string($value)),
                        ARRAY_FILTER_USE_BOTH
                    );
                    break;
                }
            }
        } catch (\Throwable) {
            // A theme without a readable layout simply contributes no pages.
        }

        return $this->pages;
    }

    /**
     * Walks the builder tree and collects reading material as typed fragments.
     *
     * Values of content fields are kept as the HTML they already are: stripping
     * their tags here would throw away headings, lists, links and emphasis that
     * the Markdown converter is able to render.
     *
     * @return array<int, array{kind:string, value:string}>
     */
    private function collect($node, int $depth = 0): array
    {
        if ($depth > 40) {
            return [];
        }

        if (is_string($node)) {
            $decoded = json_decode($node, true);

            return is_array($decoded) ? $this->collect($decoded, $depth + 1) : [];
        }

        if (!is_array($node) || ($node['status'] ?? true) === false) {
            return [];
        }

        if (in_array(strtolower((string) ($node['type'] ?? '')), self::SKIP_TYPES, true)) {
            return [];
        }

        $level = $this->headingLevel($node);
        $fragments = [];

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $fragments = array_merge($fragments, $this->collect($child, $depth + 1));
                continue;
            }

            if (!is_string($child)) {
                continue;
            }

            $name = strtolower((string) $key);
            $value = trim($child);

            // Unresolved builder placeholders such as {article.title}.
            if ($value === '' || preg_match('/^\{[\w.]+\}$/', $value)) {
                continue;
            }

            if (in_array($name, self::HEADING_FIELDS, true)) {
                $fragments[] = ['kind' => 'h' . $level, 'value' => $this->plain($value)];
            } elseif (in_array($name, self::HTML_FIELDS, true)) {
                $fragments[] = ['kind' => 'html', 'value' => $value];
            } elseif (in_array($name, self::TEXT_FIELDS, true)) {
                $fragments[] = ['kind' => 'p', 'value' => $this->plain($value)];
            }
        }

        return array_values(array_filter($fragments, static fn (array $f): bool => $f['value'] !== ''));
    }

    /**
     * YOOtheme headline elements carry their own level, e.g. title_element=h1.
     */
    private function headingLevel(array $node): int
    {
        foreach (['title_element', 'element', 'title_tag'] as $key) {
            if (preg_match('/^h([1-6])$/i', (string) ($node[$key] ?? ''), $match)) {
                return (int) $match[1];
            }
        }

        return 2;
    }

    /**
     * @param array<int, array{kind:string, value:string}> $fragments
     */
    private function toHtml(array $fragments): string
    {
        $seen = [];
        $html = [];

        foreach ($fragments as $fragment) {
            $signature = $fragment['kind'] . '|' . $fragment['value'];

            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;

            $html[] = match ($fragment['kind']) {
                'html' => $fragment['value'],
                'p' => '<p>' . htmlspecialchars($fragment['value'], ENT_QUOTES, 'UTF-8') . '</p>',
                default => '<' . $fragment['kind'] . '>' . htmlspecialchars($fragment['value'], ENT_QUOTES, 'UTF-8') . '</' . $fragment['kind'] . '>',
            };
        }

        return implode("\n", $html);
    }

    /**
     * @param array<int, array{kind:string, value:string}> $fragments
     */
    private function toPlainText(array $fragments): string
    {
        return trim(implode(' ', array_map(fn (array $f): string => $this->plain($f['value']), $fragments)));
    }

    /**
     * @param array<int, array{kind:string, value:string}> $fragments
     */
    private function title(array $fragments, string $route): string
    {
        foreach ($fragments as $fragment) {
            if (str_starts_with($fragment['kind'], 'h') && mb_strlen($fragment['value']) <= 90) {
                return $fragment['value'];
            }
        }

        return ucwords(str_replace(['-', '_'], ' ', basename($route)));
    }

    private function plain(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 180 ? mb_substr($text, 0, 179) . '…' : $text;
    }
}
