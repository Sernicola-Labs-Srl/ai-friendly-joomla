<?php

declare(strict_types=1);

namespace Sernicola\Library\AiFriendly\Tests;

use PHPUnit\Framework\TestCase;
use Sernicola\Library\AiFriendly\Schema\ListingSchema;

defined('_JEXEC') || define('_JEXEC', 1);

final class ListingSchemaTest extends TestCase
{
    private const HOME = 'https://example.test/';

    private function ours(): array
    {
        return [
            ['@type' => 'WebSite', '@id' => self::HOME . '#website', 'publisher' => ['@id' => self::HOME . '#identity']],
            ['@type' => ['EducationalOrganization', 'Organization'], '@id' => self::HOME . '#identity', 'name' => 'Scuola', 'telephone' => '+39 02'],
        ];
    }

    private function page(): array
    {
        return ['url' => self::HOME . 'iniziative.html', 'title' => 'Iniziative', 'description' => 'Eventi', 'language' => 'it-IT', 'site' => 'Sito'];
    }

    public function testListingCarriesTheSameOrganizationIdAsArticles(): void
    {
        $graph = ListingSchema::graph($this->ours(), self::HOME, $this->page(), []);
        $ids = array_column($graph, '@id');

        $this->assertContains(self::HOME . '#/schema/Organization/base', $ids);
        $this->assertContains(self::HOME . '#/schema/WebSite/base', $ids);
        $this->assertStringNotContainsString('#identity', json_encode($graph));

        $org = $graph[array_search(self::HOME . '#/schema/Organization/base', $ids, true)];
        $this->assertSame('Scuola', $org['name']);
        $this->assertSame('+39 02', $org['telephone']);
    }

    public function testTheCollectionPageListsTheItems(): void
    {
        $items = [['url' => self::HOME . 'a.html', 'name' => 'A'], ['url' => '', 'name' => 'vuoto'], ['url' => self::HOME . 'b.html', 'name' => 'B']];
        $graph = ListingSchema::graph($this->ours(), self::HOME, $this->page(), $items);
        $page = end($graph);

        $this->assertSame('CollectionPage', $page['@type']);
        $this->assertSame(self::HOME . 'iniziative.html#collection', $page['@id']);
        $this->assertSame(['@id' => self::HOME . '#/schema/WebSite/base'], $page['isPartOf']);
        $this->assertSame(2, $page['mainEntity']['numberOfItems']);
        $this->assertSame(2, $page['mainEntity']['itemListElement'][1]['position']);
        $this->assertSame('B', $page['mainEntity']['itemListElement'][1]['name']);
    }

    public function testAnEmptyListingHasNoItemList(): void
    {
        $graph = ListingSchema::graph($this->ours(), self::HOME, $this->page(), []);

        $this->assertArrayNotHasKey('mainEntity', end($graph));
    }

    public function testTheListIsCapped(): void
    {
        $items = [];
        for ($i = 1; $i <= 80; $i++) {
            $items[] = ['url' => self::HOME . $i . '.html', 'name' => 'N' . $i];
        }

        $graph = ListingSchema::graph($this->ours(), self::HOME, $this->page(), $items);
        $page = end($graph);

        $this->assertSame(ListingSchema::LIMIT, $page['mainEntity']['numberOfItems']);
    }
}
