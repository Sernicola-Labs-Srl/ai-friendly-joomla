# Joomla integration matrix

Run the install, upgrade, and HTTP smoke suite against these environments before promoting a beta:

| CMS | PHP | Database |
| --- | --- | --- |
| Joomla 5.4 latest | 8.1 and 8.3 | MySQL 8 / MariaDB 10.6 |
| Joomla 6.1 latest | 8.3 and 8.4 | MySQL 8 / MariaDB 10.6 |

Required fixtures: two articles sharing an alias in different categories, homepage article, category blog, tags, nested Custom Fields, restricted article, future/unpublished article, `noindex` metadata, two associated languages, and one YOOtheme custom page.

The acceptance run installs `plg_system_aifriendly.zip` 1.0.0 first, changes every legacy setting, then installs `pkg_aifriendly.zip` and verifies migration before exercising `/llms.txt`, `/index.html.md`, article/category Markdown, admin JSON actions, Scheduled Tasks, static regeneration, and JSON-LD.


## 2.7 public addresses, descriptions and event venues

`fixtures-2.7.sql` adds the pages this release is about (a course with a YOOtheme layout, a layout-only course, off-site, online and at-school events, a page with a meta description, a noindex page, an empty noindex category, an article in a noindex category) plus a forSEF-like table (`#__forsef_urls`) and a 4SEO-like one (`#__forseo_custom_meta`). `fake-forsef/` is a test-only system plugin that behaves like forSEF: it routes the main addresses of the table and answers 404 for the technical ones, as the production site does.

1. Joomla 5.4 test site, `sef_rewrite` and `sef_suffix` on, com_content `sef_ids` = 0 (ids in addresses). Prefix `joom_`, administrator user id 963: adjust the SQL if yours differ.
2. `mariadb ... < tests/integration/fixtures-2.7.sql`.
3. Copy `fake-forsef/` to `plugins/system/fakeforsef/` and register it (`#__extensions`, type plugin, folder system, ordering above AI Friendly).
4. Options: `schema_page_rules` = `category:11=MusicEvent`, `schema_timezone` = `Europe/Rome`, a street address for the identity, and `schema_event_places` as in the deploy notes.
5. `sh tests/integration/smoke-public-urls.sh http://localhost:8089`, and `node tools/verify-llms.mjs http://localhost:8089`.

Results: `RESULTS-2026-11-18.md`.
