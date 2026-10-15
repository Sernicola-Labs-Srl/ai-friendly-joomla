<?php

declare(strict_types=1);

namespace Sernicola\Library\AiFriendly\Tests;

use PHPUnit\Framework\TestCase;
use Sernicola\Library\AiFriendly\Integration\ForSeo;

defined('_JEXEC') || define('_JEXEC', 1);

final class ForSeoCoreModeTest extends TestCase
{
    public function testSiteDocumentsAreDroppedAndTheBreadcrumbIsKept(): void
    {
        $breadcrumb = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [1]];
        $data = [
            'website' => ['@context' => 'http://schema.org', '@type' => 'WebSite', 'url' => 'https://example.test/'],
            'breadcrumb' => $breadcrumb,
        ];

        $this->assertSame(['breadcrumb' => $breadcrumb], ForSeo::withoutSiteEntities($data));
    }

    public function testAGraphKeepsItsPageLevelNodes(): void
    {
        $data = ['business' => ['@context' => 'x', '@graph' => [
            ['@type' => 'LocalBusiness', '@id' => 'a'],
            ['@type' => 'PostalAddress', '@id' => 'b'],
            ['@type' => 'FAQPage', '@id' => 'c'],
        ]]];

        $result = ForSeo::withoutSiteEntities($data);

        $this->assertSame([['@type' => 'FAQPage', '@id' => 'c']], $result['business']['@graph']);
    }

    public function testAnUntouchedPayloadIsReturnedAsIs(): void
    {
        $data = ['breadcrumb' => ['@type' => 'BreadcrumbList']];

        $this->assertSame($data, ForSeo::withoutSiteEntities($data));
        $this->assertSame([], ForSeo::withoutSiteEntities([]));
    }
}
