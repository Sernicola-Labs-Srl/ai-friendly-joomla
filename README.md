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
- `/index.html.md` for the home page
- `<canonical-path>.md` for eligible articles, contacts and YOOtheme pages
- eligible category and tag routes with `.md` for collection output

Markdown responses are public, carry `X-Robots-Tag: noindex, follow`, and expose diagnostic source/version headers. `?debug=1` adds body diagnostics only for authorised administrators, and is never cached.

## Administration

Open **Components → AI Friendly**. The Hub includes Overview, Content, Rules, Schema, and Automation sections, plus snapshots, diagnostics, static Markdown regeneration, and a timeline.

The `Content - AI Friendly` plugin adds the exclusion switch and the last generation date to the article editor.

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

Releases are published as ZIP packages on [GitHub Releases](https://github.com/Sernicola-Labs-Srl/ai-friendly-joomla/releases). The package declares an update server (`update.xml` on the `main` branch), so Joomla offers new versions from **System → Update → Extensions**. The first release that includes it must be installed manually.

## Privacy and external services

AI Friendly makes no outgoing network requests and sends no data to third parties. Everything it produces is generated from your own site and served from your own domain. The only external link is the Sernicola Labs logo in the administrator footer.

## Support

Report bugs and request features on the [issue tracker](https://github.com/Sernicola-Labs-Srl/ai-friendly-joomla/issues). Documentation is this README; an Italian version is in `README.it.md`.

## License

GNU General Public License version 2 or later. See `LICENSE`.

