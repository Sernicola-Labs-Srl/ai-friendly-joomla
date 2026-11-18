# AI Friendly for Joomla

AI Friendly exposes Joomla content to AI/LLM clients through `/llms.txt`, Markdown endpoints, and optional semantic JSON-LD.

Also available for WordPress: [Sernicola Labs AI Friendly](https://github.com/Sernicola-Labs-Srl/ai-friendly) ([wordpress.org](https://wordpress.org/plugins/sernicola-labs-ai-friendly/)).

## Requirements

- Joomla 5.4 with PHP 8.1+ or Joomla 6.1 with PHP 8.3+
- MySQL/MariaDB supported by the selected Joomla release
- Optional: YOOtheme Pro, 4SEO

## Build

Run `powershell -ExecutionPolicy Bypass -File tools/build.ps1`. The installable package is written to `dist/pkg_aifriendly.zip`.

The package installs a shared library, the AI Content Hub administrator component, and three plugins: system, content, and scheduled task. Existing `plg_system_aifriendly` 1.0.0 settings are migrated during upgrade.

## Public endpoints

- `/llms.txt`
- Optional `/llms-full.txt` after a successful full regeneration
- `/index.html.md` for the home page
- `<canonical-path>.md` for eligible articles, contacts and YOOtheme pages
- eligible category and tag routes with `.md` for collection output

Markdown responses are public, carry `X-Robots-Tag: noindex, follow`, and expose diagnostic source/version headers. `?debug=1` adds body diagnostics only for authorised administrators, and is never cached.

### Public addresses

`llms.txt`, `llms-full.txt` and the `url:` of each Markdown file use the address a visitor sees, which is also the page's canonical. The address is built with the **site** router (never the administrator's), whichever application runs the job. A site that publishes other addresses through a third-party SEF component is read from its table, detected at run time:

| Component | Table | Columns used |
| --- | --- | --- |
| forSEF | `#__forsef_urls` | `sef`, `nonsef`, `duplicate` (0 = main address) |
| sh404SEF | `#__sh404sef_urls` | `oldurl`, `newurl`, `rank` (0 = main address) |

4SEO has no router of its own and needs nothing. With none of these installed, or when an item has no row, the address is the one Joomla built: the behaviour of earlier versions. Nothing is required of the site.

The `.md` of a public address is served. A `.md` request that reaches an article by another path, such as the technical `/section/1226-alias.html.md`, answers **301** to `<public address>.md`. Pages that are noindex, unpublished, restricted, excluded, or that two articles share, are not listed.

`tools/verify-llms.mjs` checks all of it from the outside (Node 18+):

```
node tools/verify-llms.mjs https://www.example.com --json report.json
```

For each link it reports the status of the `.md` and of the HTML page without following redirects, where a redirecting or missing page ends up, noindex (header or meta tag), a canonical that is not the page's own address, and paths that look technical. The exit status is 1 when anything needs attention.

If forSEF runs before AI Friendly, it may answer a `.md` request before the plugin sees it. Check `curl -I <public address>.md`; on a 404 move **System - AI Friendly** above forSEF in **System → Plugins**.

### Descriptions in llms.txt

The text after each link is chosen, in order, from: the article's meta description; the custom meta description in 4SEO (`#__forseo_custom_meta`, `custom.description`) when 4SEO is installed; the first paragraph of prose of at least 80 characters, cut at the end of a sentence. Headings in capitals, buttons and calls to action ("Book an Open Day") are skipped, and so are YOOtheme Builder layout comments, `<style>`, `<script>` and button CSS. Tags never glue words: `<br>`, the end of a block and adjacent inline elements become spaces. A description equal to the site-wide one (Global Configuration or **Generic descriptions** in Schema) is ignored. In **Rules → Descriptions in llms.txt**:

| Option | Default | Meaning |
| --- | --- | --- |
| Description of each link (`llms_description_source`) | `auto` | `auto`: meta description, then 4SEO, then text. `meta`: never the text. `text`: never a meta description. |
| Maximum length (`llms_description_length`) | 200 | 80 to 500 characters. |
| Further phrases to skip (`llms_description_skip`) | empty | One per line; a paragraph that starts with one is not used. |

### noindex

With **Exclude noindex content** on (the default) an item is left out when its own robots setting says noindex or, failing that, a menu item pointing at it does, or its category or an ancestor does. The `.md` of a noindex category, and of a category with nothing to list in it or below it, answers 404.

## Administration

Open **Components → AI Friendly**. The Hub includes Overview, Content, Rules, Schema, and Automation sections, plus snapshots, diagnostics, static Markdown regeneration, and a timeline.

Full regeneration is a resumable job. The Hub shows its progress and Joomla Scheduled Tasks can continue it after the browser closes. Enable **Publish llms-full.txt** in Automation to write a complete public corpus to the document root; a scheduled task is needed for unattended refreshes. The option starts disabled.

The `Content - AI Friendly` plugin adds the exclusion switch and the last generation date to the article editor.

## Event venues and dates

Pages with the `Event` or `MusicEvent` rule (Schema → Page types) are published with the school as venue unless the event says otherwise. The venue is **optional**. It can come from two places, and a Custom Field of the article takes precedence over the table:

1. **Custom Fields** of the article, by name (create them under Content → Fields, context Article, plain text):

   | Field name | Content |
   | --- | --- |
   | `evento_luogo` | Venue name, e.g. `Teatro PIME` |
   | `evento_indirizzo` | `street \| postal code \| city`, e.g. `Via Mosè Bianchi 94\|20149\|Milano` |
   | `evento_url` | Venue website, or a Google Maps / OpenStreetMap link (published as `hasMap`) |
   | `evento_sameas` | One URL per line (Wikipedia, Wikidata...) |
   | `evento_online` | Address of the live stream: the event is online |
   | `evento_modalita` | `online`, `mixed` or `offline`, when it is not obvious |
   | `evento_stato` | `scheduled`, `cancelled`, `postponed`, `rescheduled` or `online` (moved online) |
   | `evento_fine` | End: `2026-04-11 20:00`, `11/04/2026 20:00`, `2026-04-12` or just `20:00` |

2. The **Event venues** table in Schema, one venue per line, matched by a keyword in the title and then in the first part of the text:

   ```
   teatro pime ; pime | Teatro PIME | Via Mosè Bianchi 94 | 20149 | Milano | https://www.teatropime.it
   blue note | Blue Note Milano | Via Borsieri 37 | 20159 | Milano | https://maps.app.goo.gl/...
   webinar ; streaming | @online | | | | https://meet.example.com/cluster
   ```

   Several keywords are separated by `;`, unknown parts are left empty, and a venue named `@online` stands for a live stream.

The event is published with a `Place` (name, `PostalAddress`, `url` or `hasMap`, `sameAs`) or, for an online event, a `VirtualLocation`; an event with both has both and `MixedEventAttendanceMode`. With nothing stated, the event keeps the school's `Place` and `OfflineEventAttendanceMode`: nothing is invented. A venue in another city does not borrow the school's country.

`endDate` is read from the page when it states it: a time range ("18:15-20:00", "dalle 18 alle 20", an end after midnight is the next day) or a date range ("dal 2 al 5 luglio"); otherwise from `evento_fine`. A second time that is not a range ("ore 18:15 e ore 21:00") is never taken as an end. `eventStatus` is `EventScheduled` unless `evento_stato` says otherwise or the title says "annullato", "rinviato", "cancelled" or "postponed".

## 4SEO integration

In **Extend 4SEO** mode the identity configured in the Schema section is merged into the entity 4SEO already publishes, rather than adding a competing one.

The bridge is **optional**: no other feature needs it, and installing AI Friendly changes nothing in 4SEO. When 4SEO is detected, the installer only shows a notice.

4SEO hooks are not Joomla events: they are registered from its functions file, `libraries/weeblr/forseo_functions.php`, which belongs to 4SEO. In **Components → AI Friendly → Schema** the **Enable the 4SEO bridge** button, after a confirmation, adds a fenced block to the end of that file (creating the file if it does not exist):

```php
// BEGIN AI Friendly bridge: added from Components > AI Friendly > Schema.
if (is_file(JPATH_LIBRARIES . '/aifriendly/forseo-bridge.php')) {
    include JPATH_LIBRARIES . '/aifriendly/forseo-bridge.php';
}
// END AI Friendly bridge
```

The **Remove the 4SEO bridge** button takes the block out again, and so does uninstalling AI Friendly: only the lines AI Friendly added are removed (together with the single `include` line that earlier versions asked users to paste), and the file is deleted only if AI Friendly created it and nothing else is left in it. A file that ends with a closing PHP tag, or that cannot be written, is left alone and the Hub shows the lines to add by hand. A 4SEO update may overwrite the file; the Hub then shows the bridge as not registered and the button adds it back.

## Extension points

The library dispatches Joomla events that other extensions can subscribe to.
Providers are collected from the `aifriendly` plugin group, which is imported
before the event is dispatched; the remaining events reach any loaded plugin.

| Event | Arguments | Purpose |
| --- | --- | --- |
| `onAiFriendlyCollectProviders` | `providers` | Add or replace content providers |
| `onAiFriendlyCollectRules` | `rules` | Add inclusion rules implementing `RuleInterface` |
| `onAiFriendlyCanExpose` | `allowed`, `item`, `context` | Override the include/exclude decision |
| `onAiFriendlyPrepareMarkdown` | `item`, `markdown` | Post-process generated Markdown |
| `onAiFriendlyBuildLlms` | `content`, `site` | Rewrite the final `llms.txt` |
| `onAiFriendlyBuildSchema` | `graph`, `item`, `options` | Modify the JSON-LD graph |
| `onAiFriendlyRuntimeError` | `message`, `context` | Log faults, e.g. a rejected provider |

A provider implements `Sernicola\Library\AiFriendly\Contract\ContentProviderInterface`
and must return a unique `name()`: the registry maps a content key back to its
provider by name, so a duplicate is rejected rather than allowed to shadow.
Contributions that are not providers, throw, or use an empty name are dropped and
announced through `onAiFriendlyRuntimeError`.

Rules work the same way but fail open: a rule whose `allows()` throws is reported
and ignored, because a crashing plugin must not quietly pull content out of
`llms.txt`.

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

## Distribution

AI Friendly is listed in the [Joomla Extensions Directory](https://extensions.joomla.org/extension/site-management/seo-a-metadata/ai-friendly/). Releases are published as ZIP packages on [GitHub Releases](https://github.com/Sernicola-Labs-Srl/ai-friendly-joomla/releases). The package declares an update server (`update.xml` on the `main` branch), so Joomla offers new versions from **System → Update → Extensions**. The first release that includes it must be installed manually.

## Privacy and external services

AI Friendly makes no outgoing network requests and sends no data to third parties. Everything it produces is generated from your own site and served from your own domain. The only external link is the Sernicola Labs logo in the administrator footer.

## Support

Report bugs and request features on the [issue tracker](https://github.com/Sernicola-Labs-Srl/ai-friendly-joomla/issues). Documentation is this README; an Italian version is in `README.it.md`.

## License

GNU General Public License version 2 or later. See `LICENSE`.

