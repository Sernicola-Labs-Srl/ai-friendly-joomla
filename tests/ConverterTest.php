<?php

declare(strict_types=1);

namespace Sernicola\Library\AiFriendly\Tests;

use PHPUnit\Framework\TestCase;
use Sernicola\Library\AiFriendly\Markdown\Converter;

defined('_JEXEC') || define('_JEXEC', 1);

final class ConverterTest extends TestCase
{
    public function testPreservesSemanticMarkdown(): void
    {
        $html = '<h1>Title</h1><p>Hello <strong>world</strong> and <a href="https://example.test">link</a>.</p><blockquote>Quote</blockquote>';
        $markdown = (new Converter(true))->convert($html);
        self::assertStringContainsString('## Title', $markdown);
        self::assertStringContainsString('**world**', $markdown);
        self::assertStringContainsString('[link](https://example.test)', $markdown);
        self::assertStringContainsString('> Quote', $markdown);
    }

    public function testRemovesScriptsAndNoiseLinks(): void
    {
        $markdown = (new Converter())->convert('<script>alert(1)</script><p>Keep</p><a href="/more">Read more</a>');
        self::assertSame('Keep', $markdown);
    }
}

