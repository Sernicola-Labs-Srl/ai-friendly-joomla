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
- `/index.html.md` per la home page
- `<percorso-canonico>.md` per articoli, contatti e pagine YOOtheme ammessi
- rotte di categoria e tag con `.md` per l'output collezione

Le risposte Markdown sono pubbliche, portano `X-Robots-Tag: noindex, follow` e header diagnostici di sorgente e versione. `?debug=1` aggiunge diagnostica nel corpo solo per gli amministratori autorizzati, e non viene mai messo in cache.

## Amministrazione

Aprire **Componenti → AI Friendly**. L'Hub comprende le sezioni Overview, Content, Rules, Schema e Automation, più snapshot, diagnostica, rigenerazione dei Markdown statici e timeline.

Il plugin `Contenuto - AI Friendly` aggiunge all'editor degli articoli l'esclusione dal formato e la data dell'ultima generazione.

## Integrazione 4SEO

In modalità **Estendi 4SEO** l'identità configurata nella sezione Schema viene fusa nell'entità che 4SEO già pubblica, invece di aggiungerne una concorrente.

Gli hook di 4SEO non sono eventi Joomla: si registrano dal suo functions file. Aggiungere questa riga a `libraries/weeblr/forseo_functions.php`, creandolo con il preambolo di 4SEO se non esiste:

```php
include JPATH_LIBRARIES . '/aifriendly/forseo-bridge.php';
```

La sezione Schema mostra il percorso esatto, lo snippet copiabile e se il bridge risulta registrato.

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

Le release sono ZIP da installare manualmente. Non è configurato un update server remoto.
