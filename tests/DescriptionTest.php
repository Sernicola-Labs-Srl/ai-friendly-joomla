<?php

declare(strict_types=1);

namespace Sernicola\Library\AiFriendly\Tests;

use PHPUnit\Framework\TestCase;
use Sernicola\Library\AiFriendly\Support\Description;

defined('_JEXEC') || define('_JEXEC', 1);

final class DescriptionTest extends TestCase
{
    public function testLineBreaksAndBlocksKeepWordBoundaries(): void
    {
        $this->assertSame(
            'CLUSTER GRAPPOLO GRUPPO',
            Description::plain('<strong>CLUSTER</strong><br />GRAPPOLO<br><strong>GRUPPO</strong>')
        );
        $this->assertSame('Uno Due Tre', Description::plain('<p>Uno</p><p>Due</p><h2>Tre</h2>'));
        $this->assertSame('Uno Due', Description::plain('<li>Uno</li><li>Due</li>'));
        $this->assertSame('A B', Description::plain('<strong>A</strong><em>B</em>'));
    }

    public function testWordsSplitByInlineMarkupInsideOneWordStayTogether(): void
    {
        $this->assertSame('Musica', Description::plain('<strong>Mu</strong>sica'));
        $this->assertSame('con spazi doppi', Description::plain("con \n\n  spazi&nbsp;&nbsp;doppi"));
    }

    public function testCommentsStylesAndButtonsAreDropped(): void
    {
        $html = '<!-- {"type":"layout","children":[]} --><style>.uk-button{color:red}</style>'
            . '<p>Testo vero.</p><a class="uk-button uk-button-primary" href="/x">Prenota ora</a><button>Invia</button>';

        $this->assertSame('Testo vero.', Description::plain($html));
        $this->assertSame('Testo vero.', Description::plain('<p>Testo vero.</p><!-- {"type":"layout"'));
    }

    public function testButtonsAreKeptWhenAskedTo(): void
    {
        $this->assertSame('Vai Altro', Description::plain('<a class="btn" href="/">Vai</a><p>Altro</p>', false));
    }

    public function testYoothemeLayoutWithoutRenderedHtmlIsRead(): void
    {
        $layout = json_encode([
            'type' => 'layout',
            'children' => [[
                'type' => 'section',
                'children' => [[
                    'type' => 'headline',
                    'props' => ['content' => '<strong>CLUSTER</strong><br />GRAPPOLO'],
                ], [
                    'type' => 'button',
                    'props' => ['content' => 'Prenota un Open Day'],
                ], [
                    'type' => 'text',
                    'props' => ['content' => '<p>Dal 1999 accompagniamo bambini, ragazzi e adulti nello studio della musica, con lezioni individuali e corsi collettivi.</p>'],
                ], [
                    'type' => 'image',
                    'props' => ['content' => 'non dovrebbe comparire'],
                ]],
            ]],
        ]);
        $html = '<!-- ' . $layout . ' -->';

        $text = Description::plain($html);
        $this->assertStringContainsString('CLUSTER GRAPPOLO', $text);
        $this->assertStringNotContainsString('Prenota', $text);
        $this->assertStringNotContainsString('non dovrebbe', $text);
        $this->assertStringNotContainsString('{', $text);
        $this->assertSame(
            'Dal 1999 accompagniamo bambini, ragazzi e adulti nello studio della musica, con lezioni individuali e corsi collettivi.',
            Description::excerpt($html)
        );
    }

    public function testExcerptSkipsShoutedTitlesAndCallsToAction(): void
    {
        $html = '<p>SCUOLA DI MUSICA CLUSTER | MILANO</p><h1>CHE SPAZIO HA LA MUSICA NELLA TUA VITA?</h1>'
            . '<p>Prenota un Open Day</p><h2>CORSI DI MUSICA PER ADULTI</h2>'
            . '<p>Dal 1999 accompagniamo adulti di ogni livello nello studio di uno strumento, senza esami obbligatori. Le lezioni sono individuali.</p>';

        $this->assertSame(
            'Dal 1999 accompagniamo adulti di ogni livello nello studio di uno strumento, senza esami obbligatori. Le lezioni sono individuali.',
            Description::excerpt($html, 200)
        );
    }

    public function testExcerptTakesTheFirstParagraphOfEnoughCharacters(): void
    {
        $short = 'Breve introduzione.';
        $long = 'Questo paragrafo è abbastanza lungo da descrivere la pagina in modo utile per chi legge un elenco.';
        $this->assertSame($long, Description::excerpt('<p>' . $short . '</p><p>' . $long . '</p>'));
    }

    public function testExcerptIsCutAtTheEndOfASentence(): void
    {
        $text = 'Prima frase abbastanza lunga da stare da sola nel limite. Seconda frase che continua e che supera di molto il limite consentito per la descrizione breve.';
        $this->assertSame('Prima frase abbastanza lunga da stare da sola nel limite.', Description::excerpt('<p>' . $text . '</p>', 100));
    }

    public function testExcerptWithoutASentenceEndIsCutAtAWord(): void
    {
        $text = str_repeat('parola ', 40);
        $cut = Description::excerpt('<p>' . $text . '</p>', 60);
        $this->assertStringEndsWith('…', $cut);
        $this->assertLessThanOrEqual(61, mb_strlen($cut));
        $this->assertStringNotContainsString('paro…', $cut);
    }

    public function testDecimalPointDoesNotEndASentence(): void
    {
        $text = 'Il corso costa 3.5 euro al mese per ogni allievo iscritto e comprende tutte le lezioni previste dal programma annuale della scuola.';
        $cut = Description::cut($text, 60);
        $this->assertStringEndsWith('…', $cut);
        $this->assertStringNotContainsString('3.', mb_substr($cut, -4));
    }

    public function testNothingUsableMeansAnEmptyDescription(): void
    {
        $this->assertSame('', Description::excerpt('<h1>SOLO UN TITOLO</h1><p>Prenota subito</p>'));
        $this->assertSame('', Description::excerpt('<!-- {"type":"layout","children":[]} -->'));
        $this->assertSame('', Description::excerpt(''));
    }

    public function testSiteSpecificPhrasesAreSkipped(): void
    {
        $html = '<p>Vieni a trovarci ogni sabato mattina senza impegno, con la tua famiglia e i tuoi amici più cari.</p>'
            . '<p>Una vera descrizione del corso, scritta per essere letta da chi cerca informazioni sulla didattica.</p>';
        $this->assertStringStartsWith('Una vera', Description::excerpt($html, 200, ['Vieni a trovarci']));
    }

    public function testMetaDescriptionWinsOverTheText(): void
    {
        $html = '<p>Un paragrafo di testo abbastanza lungo da essere scelto se non ci fosse una meta description presente.</p>';
        $this->assertSame('Meta dell\'articolo.', Description::choose('auto', ['Meta dell\'articolo.', 'Meta 4SEO'], $html));
        $this->assertSame('Meta 4SEO', Description::choose('auto', ['', 'Meta 4SEO'], $html));
    }

    public function testGenericSiteDescriptionIsSkipped(): void
    {
        $html = '<p>Un paragrafo di testo abbastanza lungo da essere scelto quando la meta description è quella dell\'intero sito.</p>';
        $this->assertStringStartsWith('Un paragrafo', Description::choose('auto', ['  Scuola   di musica a Milano '], $html, ['Scuola di musica a Milano']));
    }

    public function testSourceOptions(): void
    {
        $html = '<p>Un paragrafo di testo abbastanza lungo da essere scelto se la sorgente è soltanto il testo della pagina.</p>';
        $this->assertStringStartsWith('Un paragrafo', Description::choose('text', ['Meta'], $html));
        $this->assertSame('', Description::choose('meta', [''], $html));
        $this->assertSame('Meta', Description::choose('meta', ['Meta'], $html));
    }

    public function testMetaDescriptionIsStrippedOfMarkup(): void
    {
        $this->assertSame('Uno Due', Description::choose('auto', ['<p>Uno</p><p>Due</p>'], ''));
    }
}
