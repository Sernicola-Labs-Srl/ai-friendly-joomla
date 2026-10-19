<?php

declare(strict_types=1);

namespace Sernicola\Library\AiFriendly\Tests;

use PHPUnit\Framework\TestCase;
use Sernicola\Library\AiFriendly\Dto\ContentItem;
use Sernicola\Library\AiFriendly\Service\FullCorpus;

defined('_JEXEC') || define('_JEXEC', 1);
defined('JPATH_ROOT') || define('JPATH_ROOT', sys_get_temp_dir() . '/aifriendly-corpus-test-' . getmypid());

final class FullCorpusTest extends TestCase
{
    protected function setUp(): void
    {
        if (!is_dir(JPATH_ROOT)) mkdir(JPATH_ROOT, 0700, true);
        if (is_file(FullCorpus::target())) unlink(FullCorpus::target());
    }

    protected function tearDown(): void
    {
        foreach (glob(JPATH_ROOT . '/*') ?: [] as $file) if (is_file($file)) unlink($file);
        foreach (glob(JPATH_ROOT . '/.*.tmp') ?: [] as $file) if (is_file($file)) unlink($file);
        rmdir(JPATH_ROOT);
    }

    public function testPublishesOnlyAfterCompletionAndPreservesAnExternalFile(): void
    {
        $item = new ContentItem('joomla', 'article', '1', 'en-GB', 'Page', 'https://example.test/page', '/page', '<p>Body</p>', 'Excerpt');
        $temporary = FullCorpus::begin('first');
        FullCorpus::append($temporary, $item, '# Page');
        $this->assertFileDoesNotExist(FullCorpus::target());
        $hash = FullCorpus::publish($temporary, '');
        $this->assertStringContainsString('Source: https://example.test/page', file_get_contents(FullCorpus::target()));
        $this->assertTrue(FullCorpus::state(true, $hash)['ready']);

        file_put_contents(FullCorpus::target(), 'external change');
        $second = FullCorpus::begin('second');
        $this->expectException(\RuntimeException::class);
        FullCorpus::publish($second, $hash);
    }

    public function testInterruptedAppendIsRemovedBeforeResume(): void
    {
        $temporary = FullCorpus::begin('resume');
        file_put_contents($temporary, 'committed');
        $committedBytes = filesize($temporary);
        file_put_contents($temporary, 'unfinished', FILE_APPEND);

        FullCorpus::restore($temporary, $committedBytes);

        $this->assertSame('committed', file_get_contents($temporary));
    }
}
