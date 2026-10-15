# Changelog

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
