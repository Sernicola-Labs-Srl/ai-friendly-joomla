# Joomla integration matrix

Run the install, upgrade, and HTTP smoke suite against these environments before promoting a beta:

| CMS | PHP | Database |
| --- | --- | --- |
| Joomla 5.4 latest | 8.1 and 8.3 | MySQL 8 / MariaDB 10.6 |
| Joomla 6.1 latest | 8.3 and 8.4 | MySQL 8 / MariaDB 10.6 |

Required fixtures: two articles sharing an alias in different categories, homepage article, category blog, tags, nested Custom Fields, restricted article, future/unpublished article, `noindex` metadata, two associated languages, and one YOOtheme custom page.

The acceptance run installs `plg_system_aifriendly.zip` 1.0.0 first, changes every legacy setting, then installs `pkg_aifriendly.zip` and verifies migration before exercising `/llms.txt`, `/index.html.md`, article/category Markdown, admin JSON actions, Scheduled Tasks, static regeneration, and JSON-LD.

