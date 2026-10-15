<?php

declare(strict_types=1);

namespace Sernicola\Library\AiFriendly\Tests;

use PHPUnit\Framework\TestCase;
use Sernicola\Library\AiFriendly\Integration\CoreSchema;

defined('_JEXEC') || define('_JEXEC', 1);

final class CoreSchemaTest extends TestCase
{
    private const HOME = 'https://example.test/';

    private function core(): array
    {
        return [
            ['@type' => 'Organization', '@id' => self::HOME . '#/schema/Organization/base', 'name' => 'Sito', 'url' => self::HOME],
            ['@type' => 'WebSite', '@id' => self::HOME . '#/schema/WebSite/base', 'name' => 'Sito', 'publisher' => ['@id' => self::HOME . '#/schema/Organization/base']],
            ['@type' => 'WebPage', '@id' => self::HOME . '#/schema/WebPage/base', 'about' => ['@id' => self::HOME . '#/schema/Organization/base']],
        ];
    }

    private function ours(): array
    {
        return [
            ['@type' => 'WebSite', '@id' => self::HOME . '#website', 'publisher' => ['@id' => self::HOME . '#identity'], 'potentialAction' => ['@type' => 'SearchAction']],
            ['@type' => ['EducationalOrganization', 'Organization'], '@id' => self::HOME . '#identity', 'name' => 'Scuola', 'telephone' => '+39 02'],
            ['@type' => 'OfferCatalog', '@id' => self::HOME . '#services', 'provider' => ['@id' => self::HOME . '#identity']],
        ];
    }

    public function testIdentityLandsOnTheCoreOrganizationWithoutASecondOne(): void
    {
        $graph = CoreSchema::merge($this->core(), $this->ours(), self::HOME);
        $orgId = self::HOME . '#/schema/Organization/base';

        $organizations = array_filter($graph, static fn ($n) => in_array('Organization', (array) $n['@type'], true));
        $this->assertCount(1, $organizations);

        $org = array_values($organizations)[0];
        $this->assertSame($orgId, $org['@id']);
        $this->assertSame('Scuola', $org['name']);
        $this->assertSame('+39 02', $org['telephone']);
        $this->assertSame(['EducationalOrganization', 'Organization'], $org['@type']);
    }

    public function testReferencesToOurProvisionalIdsAreRewritten(): void
    {
        $graph = CoreSchema::merge($this->core(), $this->ours(), self::HOME);
        $json = json_encode($graph);

        $this->assertStringNotContainsString('#identity', $json);
        $this->assertStringNotContainsString('#website', $json);

        $catalog = array_values(array_filter($graph, static fn ($n) => $n['@type'] === 'OfferCatalog'))[0];
        $this->assertSame(self::HOME . '#/schema/Organization/base', $catalog['provider']['@id']);
    }

    public function testWebSiteIsEnrichedNotDuplicated(): void
    {
        $graph = CoreSchema::merge($this->core(), $this->ours(), self::HOME);
        $sites = array_values(array_filter($graph, static fn ($n) => $n['@type'] === 'WebSite'));

        $this->assertCount(1, $sites);
        $this->assertSame('SearchAction', $sites[0]['potentialAction']['@type']);
        $this->assertSame(self::HOME . '#/schema/Organization/base', $sites[0]['publisher']['@id']);
    }

    public function testCorePropertiesWeDoNotSetAreKept(): void
    {
        $graph = CoreSchema::merge($this->core(), $this->ours(), self::HOME);

        $this->assertSame(self::HOME, $graph[0]['url']);
        $this->assertSame('WebPage', $graph[2]['@type']);
    }

    public function testWithoutACoreOrganizationTheGraphIsReturnedUntouched(): void
    {
        $graph = [['@type' => 'WebPage', '@id' => self::HOME . '#/schema/WebPage/base']];

        $this->assertSame($graph, CoreSchema::merge($graph, $this->ours(), self::HOME));
    }

    public function testMergingTwiceGivesTheSameGraph(): void
    {
        $once = CoreSchema::merge($this->core(), $this->ours(), self::HOME);
        $twice = CoreSchema::merge($once, $this->ours(), self::HOME);

        $this->assertSame($once, $twice);
    }
}
