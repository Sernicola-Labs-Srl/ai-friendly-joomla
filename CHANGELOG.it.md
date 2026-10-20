# Changelog

## 2.6.0

- Le pagine dello schema core hanno un ID WebPage specifico per URL e un solo nodo pagina. Gli articoli di notizie conservano l'entità Article; le altre regole arricchiscono il WebPage.
- Le descrizioni usano prima i metadati dell'articolo, poi la descrizione specifica di 4SEO, poi una frase pulita dall'introduzione. Le descrizioni generiche configurate sono escluse.
- Gli URL delle immagini diventano assoluti. Gli eventi con orario includono il fuso e possono usare campi luogo o regole per le sedi. Corsi, docenti, programmi, raccolte e concerti supportano regole e campi più ricchi.
- Gli elenchi di lingue accettano virgole; Google Maps non viene duplicato in sameAs quando è hasMap. Il Hub spiega le date di fondazione parziali.
- La release include le correzioni di routing e rigenerazione non ancora pubblicate, elencate qui sotto.

- Gli indirizzi pubblicati non portano più il suggerimento di routing `lang=*` di Joomla: le voci delle pagine elenco (per esempio un glossario) nello schema CollectionPage, l'`url` nell'intestazione delle pagine `.md`, l'header `Link` canonico, il nodo pagina quando lo scrive AI Friendly e le fonti di `/llms-full.txt`. Gli altri parametri dell'indirizzo restano.
- Una rigenerazione completa non fallisce più nella fase di pulizia su un sito con tag pubblicati senza contenuti né descrizione: quei tag non sono pagine e la scansione ora li considera, invece di segnalare un catalogo incompleto. Finora un'esecuzione così finiva in errore e `/llms-full.txt` non veniva mai pubblicato.

## 2.5.0

- La rigenerazione completa usa un job persistente e riprendibile, con coda stabile dei contenuti, avanzamento nel Hub e tempi distinti per query, generazione, pulizia e indice.
- `/llms-full.txt` opzionale pubblica il corpus Markdown pubblico solo dopo un passaggio completo riuscito; un file esterno o modificato non viene sovrascritto.
- La modifica del nome di un autore aggiorna i suoi Markdown a blocchi e invalida la cache. L'indice `/llms.txt` decodifica le entità HTML.
- Le regole Schema supportano `Service` e FAQ da Custom Fields numerati; i fondatori accettano un URL profilo e gli eventi riutilizzano il Place configurato.

## 2.4.1

- Il pacchetto ora si chiama "AI Friendly" in Gestione estensioni, come la scheda della Joomla Extensions Directory, il componente e i plugin. Nient'altro cambia.

## 2.4.0

- **Bridge 4SEO facoltativo, ora con un pulsante.** Invece di chiedere di modificare a mano un file di 4SEO, la sezione Schema ha il pulsante **Attiva il bridge 4SEO**, dopo una conferma. L'installazione di AI Friendly non cambia nulla in 4SEO e mostra solo un avviso se 4SEO è presente. Il bridge aggiunge un blocco delimitato a `libraries/weeblr/forseo_functions.php`, protetto in modo che 4SEO continui a funzionare anche se il file del bridge manca. Il pulsante **Rimuovi** e la disinstallazione di AI Friendly tolgono esattamente quelle righe, e un file creato da AI Friendly viene cancellato quando resta vuoto. Le istruzioni manuali restano disponibili per i file non modificabili.
- Dichiarato un update server, versione minima di PHP nel manifest e rifiuto delle versioni di Joomla non supportate.
- Corretto il titolo del pannello di configurazione iniziale dell'Hub, quasi illeggibile su fondo blu.
- File di lingua per pacchetto e libreria: nomi leggibili in Gestione estensioni.
- File del pacchetto sempre con fine riga LF.

## 2.3.1

- Il catalogo dei servizi non porta più la proprietà `provider`, che schema.org non prevede su `OfferCatalog` e che il validatore segnalava. Il collegamento con l'organizzazione resta: `hasOfferCatalog` sull'organizzazione e `provider` su ciascun `Service`, dove la proprietà è prevista.

## 2.3.0

- **Pagine di elenco.** Il plugin Schema.org di Joomla pubblica un grafo solo per gli articoli: le viste di elenco di com_content (categoria, categorie, archivio, in evidenza) restavano senza. Con la modalità Joomla core, queste pagine ricevono ora lo stesso grafo degli articoli (organizzazione e sito con gli stessi `@id`) più un `CollectionPage` con l'elenco di ciò che la pagina mostra (`ItemList`, fino a 50 voci: gli articoli della categoria e delle sue sottocategorie, escludendo quelli non pubblici o in noindex).
- Il provider dei contenuti Joomla accetta il filtro `category_ids`.

## 2.2.1

- Gli identificatori e gli URL delle entità di pagina (Course, Person, Event, DefinedTerm) usano l'indirizzo della pagina visitata, senza query string né frammento. Prima derivavano dalla rotta interna dell'articolo e uscivano con parametri come `?lang=*`.

## 2.2.0

- **Tipi di pagina.** Nuova opzione Schema "Tipi di pagina": una regola per riga (`article:43=FAQPage`, `category:24=Course`, `category:23=Person|Insegnante`) dice che cosa è davvero una pagina. Joomla pubblica ogni articolo come `Article` generico; con le regole il nodo della pagina diventa `FAQPage`, `ProfilePage`, `AboutPage`, `ContactPage` o `WebPage`, e gli viene associata l'entità giusta. Una regola su una categoria vale anche per le sottocategorie; le regole su un articolo hanno la precedenza. Funziona con la modalità Schema.org di Joomla.
- **FAQPage.** Domande e risposte vengono lette dal layout del builder YOOtheme (voci degli accordion, anche nascoste o ripetute vengono scartate) e, per le pagine scritte senza builder, dal markup di accordion e `<details>`. Le risposte mantengono paragrafi, elenchi, enfasi e link, con i link resi assoluti; script, attributi e tag estranei vengono tolti.
- **Course, Person, Event, DefinedTerm.** Ogni tipo diventa un'entità collegata alla pagina (`mainEntity`) e all'organizzazione (`provider`, `worksFor`, `organizer`). I termini del glossario condividono un unico `DefinedTermSet`.
- **Event senza date inventate.** La data viene letta dal testo ("Sabato 11 aprile 2026 ore 18:15", "Da giovedì 2 luglio a domenica 5 luglio"); l'anno può venire dal titolo della categoria ("Iniziative 2026"). Senza una data leggibile l'evento non viene pubblicato e la pagina resta una `WebPage`.
- Date di pubblicazione e modifica, immagine, editore e autore sui nodi delle pagine.
- Il plugin legge l'articolo corrente una volta per richiesta invece di due.

## 2.1.1

- Corretto l'`url` dell'organizzazione: usava l'URL del creatore del sito (campo "Credits") al posto della home. Con la modalità Joomla core, che sovrappone l'identità ai nodi di Joomla, il valore sbagliato finiva nel grafo.
- In modalità Joomla core il bridge 4SEO toglie dai documenti di 4SEO le entità di sito (`WebSite`, `Organization`, `LocalBusiness`, indirizzo, coordinate, logo), già pubblicate nel grafo di Joomla; breadcrumb e nodi di pagina restano.

## 2.1.0

- Corretta la modalità **Joomla core** dello Schema: il plugin ascoltava `onSchemaPrepareData`, un evento dei form di amministrazione che non pubblica nulla nel grafo. Ora usa `onSchemaBeforeCompileHead`, con cui Joomla pubblica il JSON-LD su ogni pagina.
- L'identità viene sovrapposta ai nodi che Joomla già pubblica (`Organization/base`, `WebSite/base`) invece di aggiungerne un secondo set: un'unica organizzazione per sito, con lo stesso `@id` su ogni pagina, e tutti i riferimenti del grafo restano validi. Se Joomla non pubblica l'organizzazione di base, il grafo non viene toccato.
- Nuovi campi dell'entità: tipi schema.org aggiuntivi (ad esempio `EducationalOrganization` e `LocalBusiness`), telefono, fascia di prezzo, orari di apertura (una riga per gruppo di giorni, `Mo-Th 10:30-22:00`), coordinate, URL della mappa, URL di ricerca del sito (pubblica la `SearchAction`).
- Il catalogo dei servizi è ora collegato all'organizzazione con `hasOfferCatalog`.
- Nuovi test per l'unione dei grafi.

## 2.0.3

- Risolti gli endpoint Markdown degli articoli, che rispondevano 404 su qualunque pagina raggiunta dal menu. Il segmento di un URL SEF è l'alias della voce di menu, non quello del contenuto, quindi cercarlo fra gli alias degli articoli non poteva funzionare.
- Il percorso viene ora risolto da Joomla stesso: il plugin toglie il suffisso `.md` dall'URI **prima** che l'applicazione instradi, e poi legge il risultato del router. Menu, suffisso SEF, filtro di lingua e router di terze parti sono quindi già presi in conto, senza duplicarne la logica.
- Un `.md` che il router non risolve continua a rispondere `text/plain` invece della pagina di errore del sito. Un errore che non sia un 404 passa oltre.
- Le risposte 404 dichiarano `X-AI-Friendly-Version` e `X-AI-Friendly-Reason`: `unresolved` quando il percorso non punta a nulla, `excluded` quando il contenuto esiste ma una regola lo trattiene, `route-failed` quando è il router a rifiutarlo. Dall'esterno i tre casi erano indistinguibili.

## 2.0.2

- Risolta la risoluzione dei percorsi SEF costruiti dal menu. Un URL come `/corsi/corso-di-canto-milano.html.md` restituiva 404 perché il segmento è l'alias della voce di menu, non quello dell'articolo. Il percorso viene ora interpretato dal router di Joomla, che conosce menu, suffisso SEF e prefissi di lingua; la ricerca per alias resta come ripiego.
- Stessa risoluzione applicata a contatti e tag.
- Un percorso non risolvibile durante `onAfterInitialise` non risponde più 404: il router non è utilizzabile a quel punto, quindi la richiesta prosegue e viene ritentata su `onAfterRoute`.

## 2.0.1

- Corretto l'errore fatale sugli endpoint Markdown con i file statici attivi: veniva chiamato `Joomla\Filesystem\File::read()`, metodo della vecchia classe CMS che nel package framework non esiste. Ogni `.md` con un file statico rispondeva 500.
- Corretta la risoluzione dei percorsi sui siti con suffisso SEF attivo: `/pagina.html.md` non trovava nulla, perché l'alias veniva cercato includendo il `.html`, e `/pagina.md` falliva sul confronto con la rotta ricostruita. Ora entrambe le forme risolvono, per articoli, contatti, tag e categorie.

## 2.0.0

Prima release stabile della linea 2.x. Il pacchetto installa una libreria condivisa,
il componente AI Content Hub e tre plugin: sistema, contenuto e task.

### Interfaccia

- Interfaccia dell'AI Content Hub interamente tradotta: ogni etichetta, messaggio e diagnostico proviene dai file di lingua invece di essere fissato in italiano nel codice.
- Il rail delle sezioni è una tab list ARIA con navigazione da tastiera; i pannelli hanno il focus visibile.
- Le notifiche sono un avviso di stato annunciato agli screen reader, che resta sullo schermo sugli errori invece di sparire dopo pochi secondi.
- Stato di attesa su ogni pulsante asincrono, indicatore di modifiche non salvate con barra di salvataggio, e conferma prima di abbandonare la pagina.
- I 33 campi Schema sono raggruppati in fieldset con aiuti in linea; l'esito delle rigenerazioni è un riepilogo leggibile invece di JSON grezzo.
- Supporto del tema scuro, nomi accessibili sulle azioni di riga, paginazione disattivata ai bordi ed escaping sicuro negli attributi delle tabelle.
- Strumenti dell'editor llms.txt: struttura del documento dagli heading con salto alla riga, controllo sintattico dei link e contatori aggiornati mentre si scrive. Tutto locale, nessuna chiamata di rete.
- Colophon con il logo Sernicola Labs, che segue il tema chiaro o scuro dell'amministrazione.

### Contenuti esposti

- Nuovi provider per `com_contact` e `com_tags`, entrambi disattivati per impostazione predefinita.
- I campi di un contatto vengono pubblicati solo quando com_contact li mostra già pubblicamente; gli indirizzi email richiedono un consenso ulteriore, perché Joomla li offusca proprio per scoraggiare la raccolta automatica.
- L'estrazione YOOtheme preserva la struttura: heading, liste, link ed enfasi arrivano nel Markdown. Prima veniva applicato `strip_tags()` a campi che erano già HTML.
- Il corpo degli articoli passa dai plugin di contenuto di Joomla; i tag dei moduli vengono sempre rimossi.
- Il noindex eredita dal ramo di categorie invece di guardare solo il campo dell'articolo.

### Conversione Markdown

- Gli URL relativi vengono resi assoluti: un link `/corsi/canto` era irrisolvibile per un client che legge il documento fuori dal sito.
- Opzione per puntare i link interni alla loro versione `.md`, limitata alle rotte pulite.
- Liste annidate e numerate mantengono indentazione e numerazione.
- Grassetto, corsivo e link sopravvivono dentro voci di lista e celle di tabella.
- Aggiunti definition list, `figcaption` e `mark`; i pipe nelle celle non spezzano più le tabelle.

### JSON-LD

- Integrazione con **4SEO**: l'identità AI Friendly viene fusa nell'entità che 4SEO già pubblica, tramite il filtro `forseo_structured_data`. Il bridge arricchisce un solo nodo dentro un solo documento e lascia intatto tutto il resto del payload.
- Il bersaglio deve essere compatibile con il tipo di entità configurato, e senza un'entità riconoscibile il bridge non fa nulla invece di creare un grafo che 4SEO non aveva.
- `Schema\OutputMode` risolve la modalità configurata rispetto alle estensioni realmente installate e ripiega su standalone quando il provider scelto manca.
- Scheda di stato dell'integrazione nella sezione Schema, con lo snippet da registrare nel functions file di 4SEO.

### Automazione

- Pulizia dei file Markdown obsoleti: contenuti cancellati, rinominati o esclusi lasciavano il loro `.md` su disco, e quel file continuava a essere servito. La pulizia si rifiuta di operare su una scansione parziale del catalogo.
- Cache opzionale delle risposte dinamiche con TTL configurabile, `ETag` e risposte 304. Disattivata per impostazione predefinita; le voci sono separate per lingua e livello di accesso, e l'output di debug non viene mai memorizzato.
- Corretto il cursore della rigenerazione pianificata, che restava fermo sull'offset iniziale e rielaborava sempre lo stesso batch.
- Diagnostica portata da 8 a 15 controlli, fra cui SEF disattivo, task pianificato non configurato ed errori dell'ultima esecuzione.

### Estensibilità

- Provider e regole registrabili da plugin del gruppo `aifriendly`, tramite `onAiFriendlyCollectProviders` e `onAiFriendlyCollectRules`.
- I contributi non validi vengono scartati e annunciati su `onAiFriendlyRuntimeError`; una regola che solleva un'eccezione viene ignorata invece di far sparire il contenuto.
- I sei eventi pubblici sono documentati nel README.
- Nuovo plugin `Contenuto - AI Friendly`: esclusione e data dell'ultima generazione dentro l'editor degli articoli, sulla stessa chiave usata dall'Hub.

### Correzioni

- Il toggle "Includi articoli Joomla" non era letto da alcun codice: gli articoli venivano esposti a prescindere dall'impostazione.
- L'output pubblico non contiene più stringhe italiane fisse: llms.txt, endpoint Markdown ed email di notifica passano dai file di lingua, con fallback inglese in codice.
- Rimosse le opzioni dichiarate e mai lette, e il campo dell'intervallo che non governava nulla.
- Aggiunto il `Link rel="canonical"` all'endpoint di categoria, l'unico dei quattro a non dichiararlo.

### Note di aggiornamento

- Nessun update server è configurato: il pacchetto va installato manualmente.
- L'integrazione 4SEO richiede una riga di `include` nel functions file di 4SEO; il percorso e lo snippet sono mostrati nella sezione Schema.
- Contatti, pagine tag, riscrittura dei link verso `.md` e cache sono tutti disattivati per impostazione predefinita: un aggiornamento non cambia ciò che il sito pubblica.

## 2.0.0-beta.2

- Fixed Joomla 6 filesystem compatibility and component SQL installation/upgrade paths.
- Made virtual public endpoints available before Joomla routing on both supported major versions.
- Added working `/llms.txt` security, canonical and diagnostic headers.
- Added `/index.html.md` homepage collection output and explicit HTTP status handling.
- Fixed nullable boolean handling in public debug and administrative regeneration requests.
- Added authenticated Hub JSON and standalone Schema smoke tests.

## 2.0.0-beta.1

- Nuovo package modulare per Joomla 5.4 e 6.1.
- AI Content Hub con Overview, Content, Rules, Schema e Automation.
- Provider Joomla com_content e YOOtheme, Custom Fields e multilingua.
- Regole granulari, esclusioni manuali, snapshot e timeline.
- Markdown dinamico/statico, scheduler batch, cleanup e diagnostica.
- Semantic Schema JSON-LD standalone o integrato con Joomla Schema.org.
- Migrazione automatica delle impostazioni dal plugin 1.0.0.
