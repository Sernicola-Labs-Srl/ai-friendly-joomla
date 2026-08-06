<?php

declare(strict_types=1);

namespace Sernicola\Library\AiFriendly\Tests;

use PHPUnit\Framework\TestCase;
use Sernicola\Library\AiFriendly\Dto\ContentItem;

defined('_JEXEC') || define('_JEXEC', 1);

final class ContentItemTest extends TestCase
{
    public function testIdentityAndFilenameIncludeLanguageAndId(): void
    {
        $item = new ContentItem('joomla', 'article', '42', 'it-IT', 'Titolo', 'https://example.test/a', '/a', '<p>Body</p>');
        self::assertSame('joomla:article:42:it-IT', $item->key());
        self::assertSame('joomla-article-42-it-it.md', $item->staticFilename());
    }
}

