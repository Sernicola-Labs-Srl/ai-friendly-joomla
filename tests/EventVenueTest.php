<?php

declare(strict_types=1);

namespace Sernicola\Library\AiFriendly\Tests;

use PHPUnit\Framework\TestCase;
use Sernicola\Library\AiFriendly\Dto\ContentItem;
use Sernicola\Library\AiFriendly\Schema\Page\EventDates;
use Sernicola\Library\AiFriendly\Schema\Page\EventVenue;
use Sernicola\Library\AiFriendly\Schema\PageSchema;

defined('_JEXEC') || define('_JEXEC', 1);

final class EventVenueTest extends TestCase
{
    private const HOME = 'https://example.test/';
    private const ORG = 'https://example.test/#/schema/Organization/base';
    private const RULES = "teatro pime ; pime | Teatro PIME | Via Mosè Bianchi 94 | 20149 | Milano | https://www.teatropime.it\n"
        . "blue note | Blue Note Milano | Via Borsieri 37 | 20159 | Milano | https://maps.app.goo.gl/abc123\n"
        . "# commento\n"
        . "diretta ; streaming | @online | | | | https://example.test/live\n"
        . "riga senza nome |\n";

    public function testRulesAreParsed(): void
    {
        $rules = EventVenue::rules(self::RULES);
        $this->assertCount(3, $rules);
        $this->assertSame(['teatro pime', 'pime'], $rules[0]['keywords']);
        $this->assertSame('Teatro PIME', $rules[0]['name']);
        $this->assertSame('https://www.teatropime.it', $rules[0]['url']);
    }

    public function testNothingStatedLeavesTheSchoolAsVenue(): void
    {
        $venue = EventVenue::resolve([], EventVenue::rules(self::RULES), 'Saggio di fine anno', 'Sabato 11 aprile 2026 ore 18:15', 'https://example.test/e.html');
        $this->assertNull($venue['place']);
        $this->assertNull($venue['online']);
        $this->assertNull($venue['mode']);
        $school = ['@id' => 'https://example.test/#place'];
        $this->assertSame($school, EventVenue::location($venue, $school));
        $this->assertSame('https://schema.org/OfflineEventAttendanceMode', EventVenue::attendanceMode($venue['mode']));
    }

    public function testKeywordInTheTitleThenInTheText(): void
    {
        $rules = EventVenue::rules(self::RULES);
        $byTitle = EventVenue::resolve([], $rules, 'Concerto al Teatro PIME', 'Sabato 11 aprile ore 18 al Blue Note', 'u');
        $this->assertSame('Teatro PIME', $byTitle['place']['name']);

        $byText = EventVenue::resolve([], $rules, 'Concerto di primavera', 'Sabato 11 aprile ore 18 presso il Blue Note di Milano', 'u');
        $this->assertSame('Blue Note Milano', $byText['place']['name']);
        $this->assertSame('Via Borsieri 37', $byText['place']['street']);
        $this->assertSame('https://maps.app.goo.gl/abc123', $byText['place']['hasMap']);
        $this->assertSame('', $byText['place']['url']);
    }

    public function testCustomFieldsWinOverTheTable(): void
    {
        $fields = [
            'evento_luogo' => 'Hard Rock Cafe',
            'evento_indirizzo' => 'Via Giuseppe Mazzini 1|20123|Milano',
            'evento_url' => 'https://www.hardrockcafe.com/milan',
            'evento_sameas' => "https://it.wikipedia.org/wiki/Hard_Rock_Cafe\nnon un url",
        ];
        $venue = EventVenue::resolve($fields, EventVenue::rules(self::RULES), 'Serata al Teatro PIME', 'x', 'u');
        $this->assertSame('Hard Rock Cafe', $venue['place']['name']);
        $this->assertSame('https://www.hardrockcafe.com/milan', $venue['place']['url']);
        $this->assertSame(['https://it.wikipedia.org/wiki/Hard_Rock_Cafe'], $venue['place']['sameAs']);

        $node = EventVenue::placeNode($venue['place'], ['name' => 'Scuola', 'address' => ['streetAddress' => 'Via Mosè Bianchi 96', 'addressLocality' => 'Milano', 'addressCountry' => 'IT']]);
        $this->assertSame('Place', $node['@type']);
        $this->assertSame(
            ['@type' => 'PostalAddress', 'streetAddress' => 'Via Giuseppe Mazzini 1', 'postalCode' => '20123', 'addressLocality' => 'Milano', 'addressCountry' => 'IT'],
            $node['address']
        );
        $this->assertSame('https://www.hardrockcafe.com/milan', $node['url']);
    }

    public function testCountryIsNotBorrowedForAVenueInAnotherCity(): void
    {
        $node = EventVenue::placeNode(
            ['name' => 'Teatro', 'street' => 'Via X 1', 'postal' => '00100', 'city' => 'Roma', 'url' => '', 'sameAs' => []],
            ['address' => ['addressLocality' => 'Milano', 'addressCountry' => 'IT']]
        );
        $this->assertArrayNotHasKey('addressCountry', $node['address']);
    }

    public function testVenueThatIsTheSchoolReusesItsPlace(): void
    {
        $org = ['name' => 'Scuola', 'address' => ['streetAddress' => 'Via Mosè Bianchi 96']];
        $school = ['@id' => 'https://example.test/#place'];
        $node = EventVenue::placeNode(['name' => 'Sede', 'street' => 'via mosè bianchi 96', 'postal' => '', 'city' => '', 'url' => '', 'sameAs' => []], $org, $school);
        $this->assertSame($school, $node);
    }

    public function testOnlineEventHasAVirtualLocationAndNoSchool(): void
    {
        $venue = EventVenue::resolve(['evento_online' => 'https://meet.example/abc'], [], 'Lezione aperta', 'x', 'https://example.test/e.html');
        $this->assertSame('online', $venue['mode']);
        $school = ['@id' => 'https://example.test/#place'];
        $this->assertSame(['@type' => 'VirtualLocation', 'url' => 'https://meet.example/abc'], EventVenue::location($venue, $school));
        $this->assertSame('https://schema.org/OnlineEventAttendanceMode', EventVenue::attendanceMode($venue['mode']));
    }

    public function testStreamRuleFallsBackToThePageAddress(): void
    {
        $venue = EventVenue::resolve([], EventVenue::rules("webinar | @online"), 'Webinar sulla didattica', 'x', 'https://example.test/e.html');
        $this->assertSame('https://example.test/e.html', $venue['online']);
        $this->assertSame('online', $venue['mode']);
    }

    public function testVenueAndStreamTogetherIsMixed(): void
    {
        $venue = EventVenue::resolve(['evento_luogo' => 'Sala', 'evento_online' => 'https://meet.example/abc'], [], 'Titolo', 'x', 'u');
        $this->assertSame('mixed', $venue['mode']);
        $location = EventVenue::location($venue, ['@id' => 's']);
        $this->assertCount(2, $location);
        $this->assertSame('Place', $location[0]['@type']);
        $this->assertSame('VirtualLocation', $location[1]['@type']);
        $this->assertSame('https://schema.org/MixedEventAttendanceMode', EventVenue::attendanceMode('mixed'));
    }

    public function testExplicitModeWins(): void
    {
        $venue = EventVenue::resolve(['evento_modalita' => 'In presenza', 'evento_online' => 'https://meet.example/abc'], [], 'T', 'x', 'u');
        $this->assertSame('offline', $venue['mode']);
        $this->assertSame('mixed', EventVenue::resolve(['evento_modalita' => 'Ibrido'], [], 'T', 'x', 'u')['mode']);
    }

    public function testStatus(): void
    {
        $this->assertSame('https://schema.org/EventScheduled', EventVenue::status([], 'Concerto'));
        $this->assertSame('https://schema.org/EventCancelled', EventVenue::status(['evento_stato' => 'Annullato'], 'Concerto'));
        $this->assertSame('https://schema.org/EventPostponed', EventVenue::status([], 'Concerto RINVIATO'));
        $this->assertSame('https://schema.org/EventCancelled', EventVenue::status([], 'Concerto annullato'));
        $this->assertSame('https://schema.org/EventMovedOnline', EventVenue::status(['evento_stato' => 'moved online'], 'x'));
        $this->assertSame('https://schema.org/EventRescheduled', EventVenue::status(['evento_stato' => 'rescheduled'], 'x'));
        // A field that says "scheduled" beats a word in the title.
        $this->assertSame('https://schema.org/EventScheduled', EventVenue::status(['evento_stato' => 'scheduled'], 'Concerto annullato lo scorso anno'));
    }

    public function testEndDateFromTimeRanges(): void
    {
        $this->assertSame(['start' => '2026-04-11T18:15:00+02:00', 'end' => '2026-04-11T20:00:00+02:00'], EventDates::parse('Sabato 11 aprile 2026 ore 18:15-20:00 Teatro PIME'));
        $this->assertSame(['start' => '2026-04-11T18:00:00+02:00', 'end' => '2026-04-11T20:00:00+02:00'], EventDates::parse('Sabato 11 aprile 2026 dalle 18 alle 20'));
        $this->assertSame(['start' => '2026-04-11T18:15:00+02:00', 'end' => '2026-04-11T19:30:00+02:00'], EventDates::parse('Sabato 11 aprile 2026, dalle ore 18:15 alle 19:30'));
        $this->assertSame('2026-04-12T01:00:00+02:00', EventDates::parse('11 aprile 2026 dalle 22:00 alle 01:00')['end']);
    }

    public function testASecondTimeThatIsNotARangeIsNotAnEnd(): void
    {
        $this->assertSame(['start' => '2026-04-11T18:15:00+02:00', 'end' => null], EventDates::parse('Sabato 11 aprile 2026ore 18:15 e ore 21:00 Teatro Pime'));
        $this->assertNull(EventDates::parse('Sabato 11 aprile 2026')['end']);
    }

    public function testDateRangeStillWinsOverATimeRange(): void
    {
        $this->assertSame('2026-07-05', EventDates::parse('Dal 2 luglio 2026 al 5 luglio 2026 ore 18:00-20:00')['end']);
    }

    public function testEndDateFromAField(): void
    {
        $start = '2026-04-11T18:15:00+02:00';
        $this->assertSame('2026-04-11T20:30:00+02:00', EventDates::fromField('20:30', $start));
        $this->assertSame('2026-04-11T21:00:00+02:00', EventDates::fromField('2026-04-11 21:00:00', $start));
        $this->assertSame('2026-04-12', EventDates::fromField('12/04/2026', $start));
        $this->assertSame('2026-04-12T10:00:00+02:00', EventDates::fromField('12/04/2026 10:00', $start));
        $this->assertNull(EventDates::fromField('31/02/2026', $start));
        $this->assertNull(EventDates::fromField('boh', $start));
        $this->assertNull(EventDates::fromField('', $start));
    }

    public function testEventNodeOffSiteAndOnline(): void
    {
        $item = new ContentItem(
            'joomla', 'article', '8', 'it-IT', 'Concerto al Teatro PIME', self::HOME . 'iniziative/concerto.html', '/iniziative/concerto.html',
            '<p>Sabato 11 aprile 2026 ore 18:15-20:00</p>', 'x',
            ['description' => 'Descrizione', 'date' => '2026-01-02', 'modified' => '2026-02-03', 'featured_image' => '', 'category' => 'Iniziative 2026'],
            ['category_ids' => [2]]
        );
        $graph = [
            ['@type' => 'Organization', '@id' => self::ORG, 'name' => 'Scuola', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Via Mosè Bianchi 96', 'addressLocality' => 'Milano', 'addressCountry' => 'IT']],
            ['@type' => 'Place', '@id' => self::HOME . '#place', 'name' => 'Scuola'],
            ['@type' => 'WebPage', '@id' => self::HOME . '#/schema/WebPage/base'],
            ['@type' => 'Article', '@id' => self::HOME . '#/schema/com_content/article/8', 'name' => 'x', 'isPartOf' => ['@id' => self::HOME . '#/schema/WebPage/base']],
        ];
        $rule = ['scope' => 'article', 'id' => 8, 'type' => 'MusicEvent', 'params' => []];
        $stored = fn (array $fields) => fn () => ['introtext' => '', 'fulltext' => '', 'fields' => $fields];

        $without = PageSchema::apply($graph, $item, $rule, self::HOME, $stored([]), []);
        $event = array_values(array_filter($without, static fn ($n) => ($n['@type'] ?? '') === 'MusicEvent'))[0];
        $this->assertSame(['@id' => self::HOME . '#place'], $event['location']);
        $this->assertSame('2026-04-11T20:00:00+02:00', $event['endDate']);
        $this->assertSame('https://schema.org/EventScheduled', $event['eventStatus']);
        $this->assertSame('https://schema.org/OfflineEventAttendanceMode', $event['eventAttendanceMode']);

        $with = PageSchema::apply($graph, $item, $rule, self::HOME, $stored([]), ['schema_event_places' => self::RULES]);
        $event = array_values(array_filter($with, static fn ($n) => ($n['@type'] ?? '') === 'MusicEvent'))[0];
        $this->assertSame('Teatro PIME', $event['location']['name']);
        $this->assertSame('Via Mosè Bianchi 94', $event['location']['address']['streetAddress']);
        $this->assertSame('20149', $event['location']['address']['postalCode']);
        $this->assertSame('https://www.teatropime.it', $event['location']['url']);

        $online = PageSchema::apply($graph, $item, $rule, self::HOME, $stored(['evento_online' => 'https://meet.example/abc']), []);
        $event = array_values(array_filter($online, static fn ($n) => ($n['@type'] ?? '') === 'MusicEvent'))[0];
        $this->assertSame('VirtualLocation', $event['location']['@type']);
        $this->assertSame('https://schema.org/OnlineEventAttendanceMode', $event['eventAttendanceMode']);
    }
}
