# AI Friendly per Joomla

AI Friendly espone i contenuti Joomla ai sistemi AI/LLM tramite `/llms.txt`, endpoint Markdown e JSON-LD semantico opzionale.

Disponibile anche per WordPress: [Sernicola Labs AI Friendly](https://github.com/Sernicola-Labs-Srl/ai-friendly) ([wordpress.org](https://wordpress.org/plugins/sernicola-labs-ai-friendly/)).

## Requisiti

- Joomla 5.4 con PHP 8.1+ oppure Joomla 6.1 con PHP 8.3+
- MySQL/MariaDB supportato dalla versione Joomla scelta
- Opzionale: YOOtheme Pro, 4SEO

## Build

Eseguire `powershell -ExecutionPolicy Bypass -File tools/build.ps1`. Il package installabile viene creato in `dist/pkg_aifriendly.zip`.

Il package installa la libreria condivisa, il componente amministrativo AI Content Hub e tre plugin: sistema, contenuto e Scheduled Tasks. I parametri del precedente `plg_system_aifriendly` 1.0.0 vengono migrati in upgrade.

## Endpoint pubblici

- `/llms.txt`
- `/llms-full.txt` opzionale dopo una rigenerazione completa riuscita
- `/index.html.md` per la home page
- `<percorso-canonico>.md` per articoli, contatti e pagine YOOtheme ammessi
- rotte di categoria e tag con `.md` per l'output collezione

Le risposte Markdown sono pubbliche, portano `X-Robots-Tag: noindex, follow` e header diagnostici di sorgente e versione. `?debug=1` aggiunge diagnostica nel corpo solo per gli amministratori autorizzati, e non viene mai messo in cache.

### Indirizzi pubblici

`llms.txt`, `llms-full.txt` e l'`url:` di ogni file Markdown usano l'indirizzo che vede il visitatore, che è anche il canonical della pagina. L'indirizzo si costruisce con il router del **sito** (mai quello dell'amministrazione), qualunque applicazione esegua il lavoro. Un sito che pubblica altri indirizzi tramite un componente SEF di terze parti viene letto dalla sua tabella, rilevata a runtime:

| Componente | Tabella | Colonne usate |
| --- | --- | --- |
| forSEF | `#__forsef_urls` | `sef`, `nonsef`, `duplicate` (0 = indirizzo principale) |
| sh404SEF | `#__sh404sef_urls` | `oldurl`, `newurl`, `rank` (0 = indirizzo principale) |

4SEO non ha un router proprio e non richiede nulla. Se nessuno di questi è installato, o se per un contenuto non c'è una riga, l'indirizzo è quello costruito da Joomla: il comportamento delle versioni precedenti. Al sito non serve alcuna configurazione.

Il `.md` di un indirizzo pubblico viene servito. Una richiesta `.md` che raggiunge un articolo con un altro percorso, come il tecnico `/sezione/1226-alias.html.md`, risponde **301** verso `<indirizzo pubblico>.md`. Le pagine noindex, non pubblicate, riservate, escluse, o condivise da due articoli, non vengono elencate.

`tools/verify-llms.mjs` controlla tutto dall'esterno (Node 18+):

```
node tools/verify-llms.mjs https://www.example.com --json report.json
```

Per ogni link riporta lo stato del `.md` e della pagina HTML senza seguire i redirect, dove finisce una pagina che reindirizza o non esiste, il noindex (header o meta), un canonical che non è l'indirizzo della pagina e i percorsi dall'aspetto tecnico. Lo stato di uscita è 1 se qualcosa richiede attenzione.

Se forSEF gira prima di AI Friendly può rispondere a una richiesta `.md` prima che il plugin la veda. Controllare con `curl -I <indirizzo pubblico>.md`; se risponde 404, spostare **Sistema - AI Friendly** sopra forSEF in **Sistema → Plugin**.

### Descrizioni in llms.txt

Il testo dopo ogni link viene scelto, in ordine, tra: la meta description dell'articolo; la meta description personalizzata di 4SEO (`#__forseo_custom_meta`, `custom.description`) se 4SEO è installato; il primo paragrafo di testo di almeno 80 caratteri, tagliato a fine frase. Titoli in maiuscolo, pulsanti e inviti all'azione («Prenota un Open Day») vengono saltati, come i commenti di layout di YOOtheme Builder, `<style>`, `<script>` e i CSS dei pulsanti. I tag non incollano mai le parole: `<br>`, la fine di un blocco e gli elementi inline adiacenti diventano spazi. Una descrizione uguale a quella generale del sito (Configurazione globale o **Descrizioni generiche** in Schema) viene ignorata. In **Rules → Descrizioni in llms.txt**:

| Opzione | Predefinito | Significato |
| --- | --- | --- |
| Descrizione di ogni link (`llms_description_source`) | `auto` | `auto`: meta description, poi 4SEO, poi testo. `meta`: mai il testo. `text`: mai una meta description. |
| Lunghezza massima (`llms_description_length`) | 200 | Da 80 a 500 caratteri. |
| Altre frasi da saltare (`llms_description_skip`) | vuoto | Una per riga; un paragrafo che inizia con una di esse non viene usato. |

### noindex

Con **Escludi contenuti noindex** attivo (predefinito) un contenuto viene escluso quando il suo robots dice noindex oppure, in mancanza, quando lo dice una voce di menu che punta a esso, o la sua categoria o una categoria superiore. Il `.md` di una categoria noindex, e di una categoria senza nulla da elencare in essa o sotto, risponde 404.

## Amministrazione

Aprire **Componenti → AI Friendly**. L'Hub comprende le sezioni Overview, Content, Rules, Schema e Automation, più snapshot, diagnostica, rigenerazione dei Markdown statici e timeline.

Il plugin `Contenuto - AI Friendly` aggiunge all'editor degli articoli l'esclusione dal formato e la data dell'ultima generazione.

## Luogo e date degli eventi

Le pagine con la regola `Event` o `MusicEvent` (Schema → Tipi di pagina) vengono pubblicate con la scuola come luogo, salvo che l'evento dica altro. Il luogo è **facoltativo** e può venire da due posti; un Campo personalizzato dell'articolo ha la precedenza sulla tabella:

1. **Campi personalizzati** dell'articolo, per nome (da creare in Contenuti → Campi, contesto Articolo, testo semplice):

   | Nome del campo | Contenuto |
   | --- | --- |
   | `evento_luogo` | Nome del luogo, es. `Teatro PIME` |
   | `evento_indirizzo` | `via \| CAP \| città`, es. `Via Mosè Bianchi 94\|20149\|Milano` |
   | `evento_url` | Sito del luogo, oppure link a Google Maps / OpenStreetMap (pubblicato come `hasMap`) |
   | `evento_sameas` | Un URL per riga (Wikipedia, Wikidata...) |
   | `evento_online` | Indirizzo della diretta: l'evento è online |
   | `evento_modalita` | `online`, `mixed` o `offline`, quando non è ovvio |
   | `evento_stato` | `scheduled`, `cancelled`, `postponed`, `rescheduled` oppure `online` (spostato online); valgono anche annullato, rinviato, riprogrammato |
   | `evento_fine` | Fine: `2026-04-11 20:00`, `11/04/2026 20:00`, `2026-04-12` oppure solo `20:00` |

2. La tabella **Luoghi degli eventi** in Schema, un luogo per riga, associata per parola chiave nel titolo e poi nella parte iniziale del testo:

   ```
   teatro pime ; pime | Teatro PIME | Via Mosè Bianchi 94 | 20149 | Milano | https://www.teatropime.it
   blue note | Blue Note Milano | Via Borsieri 37 | 20159 | Milano | https://maps.app.goo.gl/...
   webinar ; streaming | @online | | | | https://meet.example.com/cluster
   ```

   Più parole chiave si separano con `;`, i dati sconosciuti si lasciano vuoti e un luogo chiamato `@online` indica una diretta.

L'evento esce con un `Place` (nome, `PostalAddress`, `url` o `hasMap`, `sameAs`) oppure, se online, con un `VirtualLocation`; un evento con entrambi ha entrambi e `MixedEventAttendanceMode`. Se non è dichiarato nulla l'evento conserva il `Place` della scuola e `OfflineEventAttendanceMode`: non si inventa niente. Un luogo in un'altra città non prende in prestito il paese della scuola.

`endDate` si ricava dalla pagina quando lo dichiara: un intervallo orario («18:15-20:00», «dalle 18 alle 20»; una fine dopo la mezzanotte è il giorno dopo) o un intervallo di date («dal 2 al 5 luglio»); altrimenti da `evento_fine`. Un secondo orario che non è un intervallo («ore 18:15 e ore 21:00») non è mai preso come fine. `eventStatus` è `EventScheduled` salvo che `evento_stato` dica altro o il titolo contenga «annullato», «rinviato», «cancelled» o «postponed».

## Integrazione 4SEO

In modalità **Estendi 4SEO** l'identità configurata nella sezione Schema viene fusa nell'entità che 4SEO già pubblica, invece di aggiungerne una concorrente.

Il bridge è **facoltativo**: nessun'altra funzione ne ha bisogno e l'installazione di AI Friendly non cambia nulla in 4SEO. Se 4SEO è presente, l'installer mostra solo un avviso.

Gli hook di 4SEO non sono eventi Joomla: si registrano dal suo functions file, `libraries/weeblr/forseo_functions.php`, che appartiene a 4SEO. In **Componenti → AI Friendly → Schema** il pulsante **Attiva il bridge 4SEO**, dopo una conferma, aggiunge un blocco delimitato in fondo a quel file (creandolo se non esiste):

```php
// BEGIN AI Friendly bridge: added from Components > AI Friendly > Schema.
if (is_file(JPATH_LIBRARIES . '/aifriendly/forseo-bridge.php')) {
    include JPATH_LIBRARIES . '/aifriendly/forseo-bridge.php';
}
// END AI Friendly bridge
```

Il pulsante **Rimuovi il bridge 4SEO** toglie di nuovo il blocco, e lo stesso fa la disinstallazione di AI Friendly: vengono tolte solo le righe aggiunte da AI Friendly (e la singola riga `include` che le versioni precedenti chiedevano di incollare), e il file viene cancellato solo se l'ha creato AI Friendly e non contiene altro. Un file che termina con un tag PHP di chiusura, o che non è scrivibile, non viene toccato e l'Hub mostra le righe da aggiungere a mano. Un aggiornamento di 4SEO può sovrascrivere il file: l'Hub mostra allora il bridge come non registrato e il pulsante lo riaggiunge.

## Punti di estensione

La libreria lancia eventi Joomla a cui altre estensioni possono iscriversi. I provider vengono raccolti dal gruppo plugin `aifriendly`, importato prima del dispatch; gli altri eventi raggiungono qualunque plugin già caricato.

| Evento | Argomenti | Scopo |
| --- | --- | --- |
| `onAiFriendlyCollectProviders` | `providers` | Aggiungere o sostituire i provider di contenuto |
| `onAiFriendlyCollectRules` | `rules` | Aggiungere regole che implementano `RuleInterface` |
| `onAiFriendlyCanExpose` | `allowed`, `item`, `context` | Ribaltare la decisione di inclusione |
| `onAiFriendlyPrepareMarkdown` | `item`, `markdown` | Post-elaborare il Markdown generato |
| `onAiFriendlyBuildLlms` | `content`, `site` | Riscrivere il `llms.txt` finale |
| `onAiFriendlyBuildSchema` | `graph`, `item`, `options` | Modificare il grafo JSON-LD |
| `onAiFriendlyRuntimeError` | `message`, `context` | Registrare guasti, es. un provider scartato |

Un provider implementa `Sernicola\Library\AiFriendly\Contract\ContentProviderInterface` e deve restituire un `name()` univoco: il registro risale dal content key al provider tramite il nome, quindi un duplicato viene scartato invece di oscurare il primo. I contributi che non sono provider, che sollevano eccezioni o che usano un nome vuoto vengono scartati e annunciati su `onAiFriendlyRuntimeError`.

Le regole funzionano allo stesso modo ma falliscono in apertura: una regola il cui `allows()` solleva un'eccezione viene segnalata e ignorata, perché un plugin rotto non deve togliere silenziosamente contenuti da `llms.txt`.

```php
// plugins/aifriendly/myprovider/src/Extension/MyProvider.php
public static function getSubscribedEvents(): array
{
    return ['onAiFriendlyCollectProviders' => 'collect'];
}

public function collect(EventInterface $event): void
{
    $providers = $event->getArgument('providers');
    $providers[] = new ShopProvider();
    $event->setArgument('providers', $providers);
}
```

## Distribuzione

AI Friendly è presente nella [Joomla Extensions Directory](https://extensions.joomla.org/extension/site-management/seo-a-metadata/ai-friendly/). Le release sono pubblicate come pacchetti ZIP su [GitHub Releases](https://github.com/Sernicola-Labs-Srl/ai-friendly-joomla/releases). Il pacchetto dichiara un update server (`update.xml` sul branch `main`): Joomla propone le nuove versioni da **Sistema → Aggiorna → Estensioni**. La prima release che lo include va installata a mano.

## Privacy e servizi esterni

AI Friendly non effettua richieste di rete in uscita e non invia dati a terzi. Tutto ciò che produce nasce dal tuo sito e viene servito dal tuo dominio. L'unico link esterno è il logo Sernicola Labs nel piè di pagina dell'amministrazione.

## Supporto

Bug e richieste vanno aperti sull'[issue tracker](https://github.com/Sernicola-Labs-Srl/ai-friendly-joomla/issues).

## Licenza

GNU General Public License versione 2 o successiva. Vedi `LICENSE`.
