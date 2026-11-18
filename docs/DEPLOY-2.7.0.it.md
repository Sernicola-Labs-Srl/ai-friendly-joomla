# AI Friendly 2.7.0 — installazione, verifica e ripristino

Pacchetto: `dist/pkg_aifriendly-2.7.0.zip` (identico a `dist/pkg_aifriendly.zip`).
Pacchetto di ripristino: `dist/pkg_aifriendly-2.6.1.zip`.

La 2.7.0 **non modifica tabelle** (nessuna migrazione SQL) e non cambia le impostazioni esistenti: le opzioni nuove hanno valori predefiniti che mantengono il comportamento attuale, salvo i quattro punti elencati nel CHANGELOG (indirizzi pubblici, descrizioni, noindex di categoria, luogo degli eventi). Per questo il ripristino è semplice.

## 1. Prima di installare

1. Backup del sito come di consueto (file e database).
2. Annotare le impostazioni attuali: **Componenti → AI Friendly → Schema** (soprattutto «Tipi di pagina», «Luoghi degli eventi» e «Descrizioni generiche»).
3. Se il file `.zip` va caricato sul server, usare `scp` o `sftp` (porta 2222 del ticket Warpgate), mai un comando `ssh` che trasferisce il file.

## 2. Installazione

1. **Sistema → Installa → Estensioni → Carica file pacchetto** e scegliere `pkg_aifriendly-2.7.0.zip`. L'estensione si aggiorna sul posto: restano abilitati i plugin e le impostazioni.
2. Controllare **Componenti → AI Friendly**: nell'intestazione compare la versione 2.7.0.
3. **Sistema → Plugin**: se `forSEF` è installato, `Sistema - AI Friendly` deve stare **sopra** `forSEF` nell'ordinamento. Il controllo con `curl` qui sotto lo conferma.

## 3. Impostazioni nuove (tutte facoltative)

- **Rules → Descrizioni in llms.txt**: predefinito «Meta description se presente, altrimenti estratto ripulito del testo», lunghezza 200. Il predefinito è la scelta consigliata. In «Altre frasi da saltare» si possono aggiungere gli inviti all'azione tipici del sito (uno per riga).
- **Schema → Luoghi degli eventi**: una riga per luogo ricorrente, nel formato `parola chiave ; altra parola | nome | via | CAP | città | sito o link alla mappa`. Esempio:

  ```
  teatro pime ; pime | Teatro PIME | Via Mosè Bianchi 94 | 20149 | Milano | https://www.teatropime.it
  blue note | Blue Note Milano | Via Borsieri 37 | 20159 | Milano | https://www.bluenotemilano.com
  teatro menotti | Teatro Menotti | Via Ciro Menotti 11 | 20129 | Milano | https://www.teatromenotti.org
  mare culturale urbano | Mare Culturale Urbano | Via Gabetti 15 | 20147 | Milano |
  ```

  Gli indirizzi qui sopra sono un esempio: **verificarli prima di pubblicarli** (il plugin non li controlla e non ne inventa).
- **Campi personalizzati** (facoltativi, per i casi che la tabella non copre): `evento_luogo`, `evento_indirizzo`, `evento_url`, `evento_sameas`, `evento_online`, `evento_modalita`, `evento_stato`, `evento_fine`. Elenco e formati nel README.

Salvare le impostazioni nel Hub.

## 4. Rigenerazione e cache

1. **Hub → Automation → Forza rigenerazione**: ricostruisce i Markdown statici (con il nuovo `url:` pubblico nel front matter) e `llms-full.txt`. Se è attivo il task pianificato, lasciarlo finire; il Hub mostra l'avanzamento.
2. Svuotare la cache **LiteSpeed**: dal pannello del server (Enhance → sito → LiteSpeed Cache → Purge all) o dal plugin LiteSpeed Cache, se presente. Poi, se si vuole essere sicuri, richiedere una volta ciascuno `/llms.txt`, `/llms-full.txt` e una pagina `.md` perché vengano rigenerati.
3. Se il sito usa la cache dinamica di AI Friendly (`dynamic_cache_ttl` maggiore di 0), si aggiorna da sola al cambio di impostazioni.

## 5. Verifica con curl

Da una macchina qualsiasi (non serve il server). In PowerShell usare `curl.exe`.

```
curl.exe -s https://www.scuoladimusicacluster.it/llms.txt | head -n 20
curl.exe -sI https://www.scuoladimusicacluster.it/llms.txt | findstr /i "x-ai-friendly-version"
```

Attesi: link senza percorsi tecnici (`/corsi/…` con indirizzo pubblico, senza `1226-`), versione `2.7.0`, descrizioni leggibili.

```
curl.exe -sI https://www.scuoladimusicacluster.it/corsi/corso-di-musica-per-adulti.html.md
curl.exe -sI https://www.scuoladimusicacluster.it/corsi-cluster/1226-corsi-di-musica-per-adulti.html.md
```

Attesi: il primo `200`; il secondo `301` con `Location` che punta al primo. Se il primo risponde 404, vedere il punto 3 dell'installazione (ordine dei plugin).

Controllo completo di tutti i link (richiede Node 18+, dal repository):

```
node tools/verify-llms.mjs https://www.scuoladimusicacluster.it --json report.json
```

Atteso: «with problems: 0». Ogni riga con problemi dice perché (stato `.md`, redirect, 404, noindex, canonical diverso, percorso tecnico) e dove finisce un link che reindirizza. Lo script fa due richieste per link, quattro alla volta: meglio fuori dalle ore di punta.

Eventi:

```
curl.exe -s https://www.scuoladimusicacluster.it/<pagina-di-un-evento-fuori-sede>.html | findstr /i "Teatro"
```

Atteso: nel JSON-LD `location` è un `Place` con il nome e l'indirizzo del luogo, non `#place` della scuola. Si può ricontrollare con il «Rich Results Test» di Google.

## 6. Ripristino alla 2.6.1

1. **Sistema → Installa → Estensioni → Carica file pacchetto** e scegliere `pkg_aifriendly-2.6.1.zip`. Joomla sovrascrive i file; non serve disinstallare.
2. Le opzioni aggiunte dalla 2.7.0 restano nei parametri del componente e la 2.6.1 le ignora.
3. Ripetere la rigenerazione e lo svuotamento della cache LiteSpeed (punto 4).
4. Controllo: `curl.exe -sI https://www.scuoladimusicacluster.it/llms.txt | findstr /i "x-ai-friendly-version"` deve riportare `2.6.1`.

Il ripristino è stato provato sul sito di collaudo (Joomla 5.4.7, PHP 8.3): installazione della 2.6.1 sopra la 2.7.0 e successiva reinstallazione della 2.7.0, senza errori.

## Cosa non è stato possibile provare in locale

- **forSEF reale**: non è disponibile nel collaudo. La lettura della tabella `#__forsef_urls` segue le colonne indicate (`sef`, `nonsef`, `duplicate`, con `duplicate = 0` per l'indirizzo principale) ed è stata provata contro una tabella e un plugin di prova con lo stesso comportamento. Se in produzione la tabella usa un altro significato di `duplicate`, i link restano quelli di Joomla (nessun danno) e `verify-llms.mjs` lo mostra subito.
- **4SEO reale**: la lettura di `#__forseo_custom_meta` (colonna `url` e JSON `data`, chiave `custom.description`) è provata contro una tabella di prova. L'indirizzo salvato nella colonna `url` può avere forme diverse: il plugin ne prova diverse (assoluto, solo percorso, con e senza barre); se 4SEO ne usa un'altra, la descrizione 4SEO viene ignorata e si passa all'estratto del testo.
- **YOOtheme Pro reale**: il layout del builder è stato provato con articoli di prova (commento JSON con e senza HTML renderizzato).
