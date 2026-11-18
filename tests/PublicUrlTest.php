<?php

declare(strict_types=1);

namespace Sernicola\Library\AiFriendly\Tests;

use PHPUnit\Framework\TestCase;
use Sernicola\Library\AiFriendly\Support\PublicUrl;
use Sernicola\Library\AiFriendly\Support\SeoMeta;

defined('_JEXEC') || define('_JEXEC', 1);

final class PublicUrlTest extends TestCase
{
    private function index(): array
    {
        return PublicUrl::index([
            // The technical address is recorded as a duplicate of the public one.
            ['sef' => 'corsi-cluster/1226-corsi-di-musica-per-adulti.html', 'nonsef' => 'index.php?option=com_content&view=article&id=1226&catid=12&Itemid=101', 'main' => false],
            ['sef' => 'corsi/corso-di-musica-per-adulti.html', 'nonsef' => 'index.php?option=com_content&view=article&id=1226:corsi-di-musica-per-adulti&catid=12&Itemid=101', 'main' => true],
            ['sef' => 'en/courses/adult-courses.html', 'nonsef' => 'index.php?option=com_content&view=article&id=1226&lang=en', 'main' => true],
            ['sef' => 'news/', 'nonsef' => 'index.php?option=com_content&view=category&id=7&Itemid=9', 'main' => true],
            ['sef' => 'altro', 'nonsef' => 'index.php?option=com_contact&view=contact&id=1226', 'main' => true],
            ['sef' => '', 'nonsef' => 'index.php?option=com_content&view=article&id=5', 'main' => true],
            ['sef' => 'rotto', 'nonsef' => 'qualcosa di strano', 'main' => true],
        ]);
    }

    public function testPicksTheMainAddressNotTheDuplicate(): void
    {
        $this->assertSame('corsi/corso-di-musica-per-adulti.html', PublicUrl::pick($this->index(), 'com_content', 'article', 1226, 'it-IT'));
    }

    public function testRowOfAnotherLanguageIsNotUsed(): void
    {
        $this->assertSame('en/courses/adult-courses.html', PublicUrl::pick($this->index(), 'com_content', 'article', 1226, 'en-GB'));
    }

    public function testUnknownItemYieldsNothingSoTheBuiltAddressStays(): void
    {
        $this->assertNull(PublicUrl::pick($this->index(), 'com_content', 'article', 999, 'it-IT'));
        $this->assertNull(PublicUrl::pick([], 'com_content', 'article', 1226));
    }

    public function testViewAndComponentAreKeptApart(): void
    {
        $this->assertSame('news/', PublicUrl::pick($this->index(), 'com_content', 'category', 7));
        $this->assertNull(PublicUrl::pick($this->index(), 'com_content', 'article', 7));
        $this->assertSame('altro', PublicUrl::pick($this->index(), 'com_contact', 'contact', 1226));
    }

    public function testReverseFindsTheArticleOfAnyRecordedAddress(): void
    {
        $index = $this->index();
        $this->assertSame(1226, PublicUrl::reverse($index, '/corsi/corso-di-musica-per-adulti'));
        $this->assertSame(1226, PublicUrl::reverse($index, '/corsi/corso-di-musica-per-adulti.html'));
        // The technical address still reaches the page, so it can be redirected.
        $this->assertSame(1226, PublicUrl::reverse($index, '/corsi-cluster/1226-corsi-di-musica-per-adulti.html'));
        $this->assertSame(1226, PublicUrl::reverse($index, 'https://example.test/CORSI/corso-di-musica-per-adulti.html?x=1'));
        $this->assertNull(PublicUrl::reverse($index, '/non-esiste'));
        $this->assertNull(PublicUrl::reverse($index, '/'));
        $this->assertNull(PublicUrl::reverse($index, '/news', 'com_content', 'article'));
    }

    public function testRowsWithoutAddressOrUnreadableQueryAreIgnored(): void
    {
        $this->assertSame([], PublicUrl::index([['sef' => '', 'nonsef' => 'index.php?option=com_content&view=article&id=5', 'main' => true]]));
        $this->assertSame([], PublicUrl::index([['sef' => 'x', 'nonsef' => 'index.php?option=com_content&view=article', 'main' => true]]));
        $this->assertSame([], PublicUrl::index([['sef' => 'x', 'nonsef' => '', 'main' => true]]));
    }

    public function testHtmlEntitiesInTheStoredQueryAreDecoded(): void
    {
        $index = PublicUrl::index([['sef' => 'a.html', 'nonsef' => 'index.php?option=com_content&amp;view=article&amp;id=3', 'main' => true]]);
        $this->assertSame('a.html', PublicUrl::pick($index, 'com_content', 'article', 3));
    }

    public function testSeoMetaReadsTheCustomDescription(): void
    {
        $this->assertSame('Una descrizione.', SeoMeta::fromData('{"custom":{"description":" Una descrizione. "}}'));
        $this->assertSame('', SeoMeta::fromData('{"custom":{"title":"x"}}'));
        $this->assertSame('', SeoMeta::fromData('non json'));
        $this->assertSame('', SeoMeta::fromData('{"custom":{"description":["x"]}}'));
    }

    public function testSeoMetaCandidatesCoverTheSpellings(): void
    {
        $list = SeoMeta::candidates('https://example.test/corsi/x.html');
        foreach (['https://example.test/corsi/x.html', '/corsi/x.html', 'corsi/x.html'] as $expected) {
            $this->assertContains($expected, $list);
        }
        $this->assertContains('/x.html', SeoMeta::candidates('https://example.test/sub/x.html', '/sub'));
    }
}
