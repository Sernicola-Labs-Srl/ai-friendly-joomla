<?php

declare(strict_types=1);

namespace Sernicola\Library\AiFriendly\Tests;

use PHPUnit\Framework\TestCase;
use Sernicola\Library\AiFriendly\Dto\ContentItem;
use Sernicola\Library\AiFriendly\Schema\Page\EventDates;
use Sernicola\Library\AiFriendly\Schema\Page\FaqExtractor;
use Sernicola\Library\AiFriendly\Schema\Page\HtmlText;
use Sernicola\Library\AiFriendly\Schema\PageSchema;

defined('_JEXEC') || define('_JEXEC', 1);

final class PageSchemaTest extends TestCase
{
    private const HOME = 'https://example.test/';
    private const ORG = 'https://example.test/#/schema/Organization/base';

    private function item(int $id = 43, string $title = 'FAQ', string $body = '', string $category = 'Pagina'): ContentItem
    {
        return new ContentItem(
            'joomla', 'article', (string) $id, 'it-IT', $title, self::HOME . 'pagina.html', '/pagina.html', $body, 'Estratto',
            ['description' => 'Descrizione', 'date' => '2026-01-02', 'modified' => '2026-02-03', 'featured_image' => '', 'category' => $category],
            ['category_ids' => [2]]
        );
    }

    private function graph(int $id = 43): array
    {
        return [
            ['@type' => 'Organization', '@id' => self::ORG, 'name' => 'Scuola', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Milano', 'addressCountry' => 'IT']],
            ['@type' => 'WebPage', '@id' => self::HOME . '#/schema/WebPage/base'],
            ['@type' => 'Article', '@id' => self::HOME . '#/schema/com_content/article/' . $id, 'name' => 'x', 'headline' => 'FAQ', 'isPartOf' => ['@id' => self::HOME . '#/schema/WebPage/base']],
        ];
    }

    private function layoutText(): string
    {
        return '<h1>Titolo</h1>' . "\n" . '<!-- ' . file_get_contents(__DIR__ . '/fixtures/faq-layout.json') . ' -->';
    }

    public function testRulesAreParsedAndArticleRulesComeFirst(): void
    {
        $rules = PageSchema::rules("category:24=Course\n# commento\nnonsense\ncategory:23=Person|Insegnante\narticle:55=Person|Direttrice\narticle:9=Nope\n");

        $this->assertCount(3, $rules);
        $this->assertSame('article', $rules[0]['scope']);
        $this->assertSame(['Direttrice'], $rules[0]['params']);
        $this->assertSame('Course', $rules[1]['type']);
    }

    public function testMatchPrefersTheArticleAndReachesSubcategories(): void
    {
        $rules = PageSchema::rules("category:23=Person|Insegnante\narticle:55=Person|Direttrice\ncategory:25=Event");

        $this->assertSame(['Direttrice'], PageSchema::match($rules, 55, [23, 1])['params']);
        $this->assertSame('Person', PageSchema::match($rules, 56, [23, 1])['type']);
        $this->assertSame('Event', PageSchema::match($rules, 700, [61, 25, 1])['type']);
        $this->assertNull(PageSchema::match($rules, 700, [2, 1]));
    }

    public function testFaqIsReadFromTheBuilderLayoutWithoutHiddenOrRepeatedItems(): void
    {
        $faq = FaqExtractor::extract('', $this->layoutText(), self::HOME);
        $questions = array_column($faq, 'question');

        $this->assertCount(4, $faq);
        $this->assertNotContains('Voce nascosta', $questions);
        $this->assertSame(count($questions), count(array_unique($questions)));
        $this->assertStringContainsString('Assolutamente', $faq[1]['answer']);
    }

    public function testFaqPageGetsQuestionsAndKeepsJoomlasNode(): void
    {
        $text = $this->layoutText();
        $graph = PageSchema::apply($this->graph(), $this->item(), ['scope' => 'article', 'id' => 43, 'type' => 'FAQPage', 'params' => []], self::HOME, fn () => ['introtext' => '', 'fulltext' => $text]);
        $page = $graph[2];

        $this->assertSame('FAQPage', $page['@type']);
        $this->assertSame(self::HOME . '#/schema/com_content/article/43', $page['@id']);
        $this->assertCount(4, $page['mainEntity']);
        $this->assertSame('Question', $page['mainEntity'][0]['@type']);
        $this->assertSame('Answer', $page['mainEntity'][0]['acceptedAnswer']['@type']);
        $this->assertSame(['@id' => self::ORG], $page['publisher']);
        $this->assertSame('2026-01-02', $page['datePublished']);
        $this->assertArrayNotHasKey('headline', $page);
    }

    public function testAFaqPageWithoutQuestionsFallsBackToWebPage(): void
    {
        $graph = PageSchema::apply($this->graph(), $this->item(), ['scope' => 'article', 'id' => 43, 'type' => 'FAQPage', 'params' => []], self::HOME, fn () => ['introtext' => '<p>Niente</p>', 'fulltext' => '']);

        $this->assertSame('WebPage', $graph[2]['@type']);
        $this->assertArrayNotHasKey('mainEntity', $graph[2]);
    }

    public function testAnswersLoseScriptsAndKeepAbsoluteLinks(): void
    {
        $html = HtmlText::sanitize('<p onclick="x()">Vai <a href="/corsi/a.html" target="_blank">qui</a><script>alert(1)</script> <span>ora</span></p>', self::HOME);

        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('target', $html);
        $this->assertStringContainsString('href="https://example.test/corsi/a.html"', $html);
        $this->assertStringNotContainsString('<span', $html);
    }

    public function testMarkupFallbackReadsAccordionsAndDetails(): void
    {
        $html = '<div uk-accordion><div class="el-item"><a class="el-title uk-accordion-title" href>Prima?</a><div class="uk-accordion-content"><div class="el-content"><p>Si.</p></div></div></div></div>'
            . '<details><summary>Seconda?</summary><p>No.</p></details>';

        $faq = FaqExtractor::extract($html, '', self::HOME);

        $this->assertSame(['Prima?', 'Seconda?'], array_column($faq, 'question'));
    }

    public function testCourseAndPersonBecomeEntitiesOfTheirPage(): void
    {
        $course = PageSchema::apply($this->graph(7), $this->item(7, 'Corso di Pianoforte'), ['scope' => 'category', 'id' => 24, 'type' => 'Course', 'params' => []], self::HOME, fn () => ['introtext' => '', 'fulltext' => '']);
        $this->assertSame('Course', $course[3]['@type']);
        $this->assertSame(['@id' => self::ORG], $course[3]['provider']);
        $this->assertSame(['@id' => self::HOME . 'pagina.html#course'], $course[2]['mainEntity']);

        $person = PageSchema::apply($this->graph(7), $this->item(7, 'Anna Rossi'), ['scope' => 'category', 'id' => 23, 'type' => 'Person', 'params' => ['Insegnante']], self::HOME, fn () => ['introtext' => '', 'fulltext' => '']);
        $this->assertSame('ProfilePage', $person[2]['@type']);
        $this->assertSame('Insegnante', $person[3]['jobTitle']);
        $this->assertSame(['@id' => self::ORG], $person[3]['worksFor']);
    }

    public function testEventIsPublishedOnlyWithADate(): void
    {
        $dated = $this->item(8, 'Concerto', '<p>Sabato 11 aprile 2026 ore 18:15 Teatro Pime</p>', 'Iniziative 2026');
        $graph = PageSchema::apply($this->graph(8), $dated, ['scope' => 'category', 'id' => 25, 'type' => 'Event', 'params' => []], self::HOME, fn () => ['introtext' => '', 'fulltext' => '']);

        $this->assertSame('Event', $graph[3]['@type']);
        $this->assertSame('2026-04-11T18:15', $graph[3]['startDate']);
        $this->assertSame('Scuola', $graph[3]['location']['name']);

        $undated = $this->item(8, 'Concerto', '<p>Prossimamente</p>', 'Iniziative');
        $graph = PageSchema::apply($this->graph(8), $undated, ['scope' => 'category', 'id' => 25, 'type' => 'Event', 'params' => []], self::HOME, fn () => ['introtext' => '', 'fulltext' => '']);

        $this->assertCount(3, $graph);
        $this->assertSame('WebPage', $graph[2]['@type']);
    }

    public function testGlossaryTermsShareOneTermSet(): void
    {
        $graph = PageSchema::apply($this->graph(9), $this->item(9, 'Sincope'), ['scope' => 'category', 'id' => 39, 'type' => 'DefinedTerm', 'params' => ['Glossario di Cluster', 'https://example.test/glossario.html']], self::HOME, fn () => ['introtext' => '', 'fulltext' => '']);

        $this->assertSame('DefinedTerm', $graph[3]['@type']);
        $this->assertSame('DefinedTermSet', $graph[4]['@type']);
        $this->assertSame(self::HOME . '#glossary', $graph[4]['@id']);
        $this->assertSame('https://example.test/glossario.html', $graph[4]['url']);
    }

    public function testServiceReplacesTheArticleNodeAndUsesTheCanonicalPage(): void
    {
        $graph = PageSchema::apply($this->graph(7), $this->item(7, 'Consulenza'),
            ['scope' => 'category', 'id' => 42, 'type' => 'Service', 'params' => ['Consulenza', 'Italia']],
            self::HOME, fn () => ['introtext' => '', 'fulltext' => '']);
        $this->assertSame('WebPage', $graph[2]['@type']);
        $this->assertSame(self::HOME . 'pagina.html#service', $graph[3]['@id']);
        $this->assertSame(['@id' => self::ORG], $graph[3]['provider']);
        $this->assertSame('Country', $graph[3]['areaServed']['@type']);
        $this->assertCount(0, array_filter($graph, static fn ($node) => ($node['@type'] ?? '') === 'Article'));
    }

    public function testConfiguredCustomFieldsProvideFaqWhenArticleMarkupHasNone(): void
    {
        $graph = PageSchema::apply($this->graph(), $this->item(),
            ['scope' => 'article', 'id' => 43, 'type' => 'FAQPage', 'params' => ['faq_question_', 'faq_answer_', 'faq_enabled']],
            self::HOME, fn () => ['introtext' => '', 'fulltext' => '', 'fields' => [
                'faq_enabled' => '1', 'faq_question_1' => 'Dove?', 'faq_answer_1' => '<p>Qui.</p>',
            ]]);
        $this->assertSame('FAQPage', $graph[2]['@type']);
        $this->assertSame('Dove?', $graph[2]['mainEntity'][0]['name']);
    }

    public function testEntityIdsUseTheAddressOfThePageWithoutParameters(): void
    {
        $graph = $this->graph(7);
        $graph[1]['url'] = 'https://example.test/corsi/pianoforte.html?utm_source=x&amp;a=1';
        $item = new ContentItem('joomla', 'article', '7', 'it-IT', 'Corso', 'https://example.test/index.php?a=1&amp;lang=*', '/x', '', '', ['description' => 'd']);

        $result = PageSchema::apply($graph, $item, ['scope' => 'category', 'id' => 24, 'type' => 'Course', 'params' => []], self::HOME, fn () => ['introtext' => '', 'fulltext' => '']);

        $this->assertSame('https://example.test/corsi/pianoforte.html#course', $result[3]['@id']);
        $this->assertSame('https://example.test/corsi/pianoforte.html', $result[3]['url']);
    }

    public function testWithoutAPageUrlTheRouteIsUsedUnescapedAndWithoutParameters(): void
    {
        $item = new ContentItem('joomla', 'article', '7', 'it-IT', 'Corso', 'https://example.test/corso.html?lang=*&amp;x=1', '/x', '', '', ['description' => 'd']);

        $result = PageSchema::apply($this->graph(7), $item, ['scope' => 'category', 'id' => 24, 'type' => 'Course', 'params' => []], self::HOME, fn () => ['introtext' => '', 'fulltext' => '']);

        $this->assertSame('https://example.test/corso.html', $result[3]['url']);
    }

    public function testNothingHappensWhenJoomlaDidNotPublishThePage(): void
    {
        $graph = $this->graph(1);

        $this->assertSame($graph, PageSchema::apply($graph, $this->item(43), ['scope' => 'article', 'id' => 43, 'type' => 'FAQPage', 'params' => []], self::HOME, fn () => ['introtext' => '', 'fulltext' => $this->layoutText()]));
    }

    public function testEventDates(): void
    {
        $this->assertSame(['start' => '2026-04-11T18:15', 'end' => null], EventDates::parse('Sabato 11 aprile 2026ore 18:15 e ore 21:00 Teatro Pime'));
        $this->assertSame(['start' => '2026-07-02', 'end' => '2026-07-05'], EventDates::parse('Da giovedì 2 luglio a domenica 5 luglio Lasciati trasportare', 2026));
        $this->assertNull(EventDates::parse('Da giovedì 2 luglio a domenica 5 luglio'));
        $this->assertNull(EventDates::parse('Il 31 febbraio 2026 non esiste'));
        $this->assertNull(EventDates::parse('Nessuna data qui'));
    }
}
