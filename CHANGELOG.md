# Changelog

## 2.6.0

- Joomla core schema pages now have URL-specific WebPage IDs and one page node. Article pages keep their Article entity; other rules attach their data to the WebPage.
- Descriptions prefer article metadata, then a specific page description from 4SEO, then a cleaned sentence from the introduction. Configured generic descriptions are excluded.
- Image URLs are absolute. Timed events include the configured time zone and may use custom venue fields or named venue rules. Course, teacher, program, collection and music event schemas accept richer page rules and custom fields.
- Organization language lists accept commas, and Google Maps URLs are excluded from sameAs when used as hasMap. The Hub explains partial founding dates.
- The release includes the previously unreleased routing and regeneration fixes listed below.

- Published addresses no longer carry Joomla's `lang=*` routing hint: the items of listing pages (for example a glossary) in the CollectionPage schema, the `url` in the front matter of `.md` pages, the canonical `Link` header, the page node when AI Friendly writes it, and the sources in `/llms-full.txt`. Other query parameters are kept.
- A complete regeneration no longer fails at the cleanup step on a site that has published tags with no entries and no description: those tags are not pages, and the scan now accounts for them instead of reporting an incomplete catalogue. Until now such a run ended as failed and `/llms-full.txt` was never published.

## 2.5.0

- Full regeneration now runs as a persistent, resumable job with a fixed content queue, bounded steps, progress in the Hub, and separate timing for queries, generation, cleanup and index construction.
- Optional `/llms-full.txt` publishes the public Markdown corpus only after a successful complete pass. An existing file is replaced or removed only when AI Friendly still owns its recorded hash.
- Author name changes refresh the author's Markdown in batches and invalidate the dynamic cache. The short `/llms.txt` index decodes HTML entities.
- Joomla Schema page rules support `Service`; FAQ rules can read numbered Joomla Custom Fields. Founders accept an optional profile URL, and Event pages reuse the configured Place where available.

An Italian version of this file is kept in `CHANGELOG.it.md`.

## 2.4.1

- The package is now named "AI Friendly" in the Extensions manager, matching the name of its Joomla Extensions Directory listing, the component and the plugins. Nothing else changes.

## 2.4.0

- **Update server.** The package now declares an update server, so Joomla can offer new releases from System → Update → Extensions. The first release that carries it has to be installed by hand; later ones arrive through Joomla.
- The package manifest declares the minimum PHP version (8.1), and the installer refuses Joomla releases older than 5.4 or in the 6.0 line, which the package does not support.
- All sub-extension manifests carry the author contact and copyright, like the package manifest.
- The error raised when a static Markdown file cannot be saved is now in English, like the rest of the code.
- The package validation script also checks `update.xml` against the declared version and download URL.
- **Optional 4SEO bridge, now with a button.** Instead of asking you to edit a 4SEO file by hand, the Schema section has an **Enable the 4SEO bridge** button, after a confirmation. Installing AI Friendly changes nothing in 4SEO and only shows a notice when 4SEO is detected. The bridge adds one fenced block to `libraries/weeblr/forseo_functions.php`, guarded so that 4SEO keeps working if the bridge file is ever missing. A **Remove** button and uninstalling AI Friendly take exactly those lines out again, and a file AI Friendly created is deleted once empty. Manual instructions remain available for files that cannot be edited.
- Fixed the title of the first-time setup panel in the Hub, which took the dark heading colour of Joomla's admin theme and was almost unreadable on the blue background.
- Language files for the package and the library, so both show a proper name in the Extensions manager instead of a raw language key.
- The Markdown converter, the HTML text helper and the static store no longer use hexadecimal escapes, which security scanners flag; behaviour is unchanged.
- Files inside the package are always built with LF line endings.
- The changelog is now in English; the Italian original lives in `CHANGELOG.it.md`.

## 2.3.1

- The service catalogue no longer carries the `provider` property, which schema.org does not define on `OfferCatalog` and which the validator flagged. The link to the organisation stays: `hasOfferCatalog` on the organisation and `provider` on each `Service`, where the property is defined.

## 2.3.0

- **Listing pages.** Joomla's Schema.org plugin publishes a graph only for articles; the com_content listing views (category, categories, archive, featured) had none. In Joomla core mode these pages now receive the same graph as articles (organisation and site with the same `@id`s) plus a `CollectionPage` listing what the page shows (`ItemList`, up to 50 entries: the articles of the category and its subcategories, excluding non-public or noindex ones).
- The Joomla content provider accepts the `category_ids` filter.

## 2.2.1

- Identifiers and URLs of page entities (Course, Person, Event, DefinedTerm) use the address of the visited page, without query string or fragment. Previously they were derived from the article's internal route and came out with parameters such as `?lang=*`.

## 2.2.0

- **Page types.** New Schema option "Page types": one rule per line (`article:43=FAQPage`, `category:24=Course`, `category:23=Person|Teacher`) says what a page really is. Joomla publishes every article as a generic `Article`; with rules the page node becomes `FAQPage`, `ProfilePage`, `AboutPage`, `ContactPage` or `WebPage`, and the right entity is attached to it. A rule on a category also applies to its subcategories; rules on an article take precedence. Works with Joomla's Schema.org mode.
- **FAQPage.** Questions and answers are read from the YOOtheme builder layout (accordion items; hidden or repeated ones are discarded) and, for pages written without the builder, from accordion and `<details>` markup. Answers keep paragraphs, lists, emphasis and links, with links made absolute; scripts, attributes and foreign tags are removed.
- **Course, Person, Event, DefinedTerm.** Each type becomes an entity linked to the page (`mainEntity`) and to the organisation (`provider`, `worksFor`, `organizer`). Glossary terms share a single `DefinedTermSet`.
- **Events without invented dates.** The date is read from the text ("Saturday 11 April 2026, 6:15 pm", "From Thursday 2 July to Sunday 5 July"); the year can come from the category title ("Events 2026"). Without a readable date the event is not published and the page stays a `WebPage`.
- Publication and modification dates, image, publisher and author on page nodes.
- The plugin reads the current article once per request instead of twice.

## 2.1.1

- Fixed the organisation `url`: it used the site creator's URL (the "Credits" field) instead of the home page. In Joomla core mode, which overlays the identity on Joomla's nodes, the wrong value ended up in the graph.
- In Joomla core mode the 4SEO bridge removes the site entities (`WebSite`, `Organization`, `LocalBusiness`, address, coordinates, logo) from 4SEO's documents, since they are already published in Joomla's graph; breadcrumbs and page nodes stay.

## 2.1.0

- Fixed the **Joomla core** Schema mode: the plugin listened to `onSchemaPrepareData`, an administrator form event that publishes nothing to the graph. It now uses `onSchemaBeforeCompileHead`, with which Joomla publishes JSON-LD on every page.
- The identity is overlaid on the nodes Joomla already publishes (`Organization/base`, `WebSite/base`) instead of adding a second set: one organisation per site, with the same `@id` on every page, and all references in the graph stay valid. If Joomla does not publish the base organisation, the graph is left untouched.
- New entity fields: additional schema.org types (for example `EducationalOrganization` and `LocalBusiness`), telephone, price range, opening hours (one line per group of days, `Mo-Th 10:30-22:00`), coordinates, map URL, site search URL (publishes the `SearchAction`).
- The service catalogue is now linked to the organisation with `hasOfferCatalog`.
- New tests for graph merging.

## 2.0.3

- Fixed the article Markdown endpoints, which answered 404 on any page reached through the menu. The segment of an SEF URL is the menu item alias, not the content alias, so looking it up among article aliases could not work.
- The path is now resolved by Joomla itself: the plugin strips the `.md` suffix from the URI **before** the application routes, then reads the router's result. Menus, SEF suffix, language filter and third-party routers are therefore already accounted for, without duplicating their logic.
- A `.md` that the router does not resolve keeps answering `text/plain` instead of the site's error page. An error that is not a 404 passes through.
- 404 responses declare `X-AI-Friendly-Version` and `X-AI-Friendly-Reason`: `unresolved` when the path points to nothing, `excluded` when the content exists but a rule holds it back, `route-failed` when the router rejects it. From outside the three cases were indistinguishable.

## 2.0.2

- Fixed resolution of SEF paths built from the menu. A URL such as `/courses/singing-course-milan.html.md` returned 404 because the segment is the menu item alias, not the article's. The path is now interpreted by Joomla's router, which knows menus, SEF suffix and language prefixes; lookup by alias remains as a fallback.
- The same resolution applies to contacts and tags.
- A path that cannot be resolved during `onAfterInitialise` no longer answers 404: the router is not usable at that point, so the request continues and is retried on `onAfterRoute`.

## 2.0.1

- Fixed the fatal error on Markdown endpoints with static files enabled: `Joomla\Filesystem\File::read()` was called, a method of the old CMS class that does not exist in the framework package. Every `.md` with a static file answered 500.
- Fixed path resolution on sites with the SEF suffix enabled: `/page.html.md` found nothing because the alias was searched including `.html`, and `/page.md` failed the comparison with the rebuilt route. Both forms now resolve, for articles, contacts, tags and categories.

## 2.0.0

First stable release of the 2.x line. The package installs a shared library, the AI Content Hub component and three plugins: system, content and task.

### Interface

- The AI Content Hub interface is fully translated: every label, message and diagnostic comes from the language files instead of being hard-coded in Italian.
- The section rail is an ARIA tab list with keyboard navigation; panels have a visible focus.
- Notifications are a status message announced to screen readers, which stays on screen on errors instead of disappearing after a few seconds.
- Busy state on every asynchronous button, unsaved-changes indicator with a save bar, and confirmation before leaving the page.
- The 33 Schema fields are grouped into fieldsets with inline help; the result of regenerations is a readable summary instead of raw JSON.
- Dark theme support, accessible names on row actions, pagination disabled at the edges and safe escaping in table attributes.
- llms.txt editor tools: document outline from headings with jump to line, link syntax check and counters updated while typing. All local, no network calls.
- Colophon with the Sernicola Labs logo, which follows the light or dark administrator theme.

### Exposed content

- New providers for `com_contact` and `com_tags`, both disabled by default.
- Contact fields are published only when com_contact already shows them publicly; email addresses require an additional consent, because Joomla obfuscates them precisely to discourage automated harvesting.
- YOOtheme extraction preserves structure: headings, lists, links and emphasis reach the Markdown. Previously `strip_tags()` was applied to fields that were already HTML.
- Article bodies go through Joomla's content plugins; form tags are always removed.
- Noindex is inherited along the category branch instead of looking only at the article field.

### Markdown conversion

- Relative URLs are made absolute: a link `/courses/singing` could not be resolved by a client reading the document outside the site.
- Option to point internal links to their `.md` version, limited to clean routes.
- Nested and numbered lists keep indentation and numbering.
- Bold, italic and links survive inside list items and table cells.
- Added definition lists, `figcaption` and `mark`; pipes in cells no longer break tables.

### JSON-LD

- **4SEO** integration: the AI Friendly identity is merged into the entity 4SEO already publishes, through the `forseo_structured_data` filter. The bridge enriches a single node inside a single document and leaves the rest of the payload intact.
- The target must be compatible with the configured entity type, and without a recognisable entity the bridge does nothing instead of creating a graph 4SEO did not have.
- `Schema\OutputMode` resolves the configured mode against the extensions actually installed and falls back to standalone when the chosen provider is missing.
- Integration status card in the Schema section, with the snippet to register in 4SEO's functions file.

### Automation

- Cleanup of stale Markdown files: deleted, renamed or excluded content used to leave its `.md` on disk, and that file kept being served. The cleanup refuses to operate on a partial catalogue scan.
- Optional cache of dynamic responses with configurable TTL, `ETag` and 304 responses. Disabled by default; entries are separated by language and access level, and debug output is never stored.
- Fixed the scheduled regeneration cursor, which stayed on the initial offset and always reprocessed the same batch.
- Diagnostics raised from 8 to 15 checks, including SEF disabled, scheduled task not configured and errors from the last run.

### Extensibility

- Providers and rules can be registered by plugins of the `aifriendly` group, through `onAiFriendlyCollectProviders` and `onAiFriendlyCollectRules`.
- Invalid contributions are discarded and announced on `onAiFriendlyRuntimeError`; a rule that throws is ignored instead of making content disappear.
- The six public events are documented in the README.
- New `Content - AI Friendly` plugin: exclusion and last generation date inside the article editor, on the same key used by the Hub.

### Fixes

- The "Include Joomla articles" toggle was not read by any code: articles were exposed regardless of the setting.
- Public output no longer contains hard-coded Italian strings: llms.txt, Markdown endpoints and notification emails go through the language files, with an English fallback in code.
- Removed options that were declared and never read, and the interval field that governed nothing.
- Added `Link rel="canonical"` to the category endpoint, the only one of the four that did not declare it.

### Upgrade notes

- No update server was configured in this release: the package had to be installed manually.
- The 4SEO integration requires an `include` line in 4SEO's functions file; the path and snippet are shown in the Schema section.
- Contacts, tag pages, rewriting of links to `.md` and the cache are all disabled by default: an upgrade does not change what the site publishes.

## 2.0.0-beta.2

- Fixed Joomla 6 filesystem compatibility and component SQL installation/upgrade paths.
- Made virtual public endpoints available before Joomla routing on both supported major versions.
- Added working `/llms.txt` security, canonical and diagnostic headers.
- Added `/index.html.md` homepage collection output and explicit HTTP status handling.
- Fixed nullable boolean handling in public debug and administrative regeneration requests.
- Added authenticated Hub JSON and standalone Schema smoke tests.

## 2.0.0-beta.1

- New modular package for Joomla 5.4 and 6.1.
- AI Content Hub with Overview, Content, Rules, Schema and Automation.
- Joomla com_content and YOOtheme providers, Custom Fields and multilingual support.
- Granular rules, manual exclusions, snapshots and timeline.
- Dynamic/static Markdown, batch scheduler, cleanup and diagnostics.
- Semantic Schema JSON-LD standalone or integrated with Joomla Schema.org.
- Automatic migration of settings from the 1.0.0 plugin.
