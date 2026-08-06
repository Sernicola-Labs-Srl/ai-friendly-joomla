<?php

declare(strict_types=1);

namespace Sernicola\Library\AiFriendly\Tests;

use PHPUnit\Framework\TestCase;
use Sernicola\Library\AiFriendly\Support\Path;

defined('_JEXEC') || define('_JEXEC', 1);

final class PathTest extends TestCase
{
    public function testNormalizesAndRemovesMarkdownSuffix(): void
    {
        self::assertSame('/news/article', Path::normalize('//news//article/'));
        self::assertSame('/news/article', Path::withoutMarkdownSuffix('/news/article.md'));
        self::assertSame('/', Path::withoutMarkdownSuffix('/index.html.md'));
    }

    public function testBuildsMarkdownUrl(): void
    {
        self::assertSame('https://example.test/news.md', Path::markdownUrl('https://example.test/news/'));
        self::assertSame('https://example.test/index.html.md', Path::markdownUrl('https://example.test/'));
    }
}

