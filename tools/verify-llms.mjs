#!/usr/bin/env node
/**
 * Walks every link of a site's llms.txt and reports what each one really does.
 *
 *   node tools/verify-llms.mjs https://www.example.com
 *   node tools/verify-llms.mjs http://localhost:8089 --json report.json
 *
 * For each link it checks, without following redirects:
 *   - the Markdown address itself (it must answer 200);
 *   - the HTML page it mirrors (200; a 3xx or a 404 means llms.txt points at a
 *     page visitors do not use);
 *   - noindex, in the `X-Robots-Tag` header or in <meta name="robots">;
 *   - the canonical of the page, which must be the page's own address;
 *   - whether the path looks like Joomla's technical one (`/section/1226-alias.html`).
 *
 * A redirecting or 404 link comes with the address it ends up at, so the list
 * can be compared with what the site publishes.
 *
 * Exit status: 0 when every link is clean, 1 when something needs attention,
 * 2 when llms.txt itself could not be read. Needs Node 18 or later. Makes two
 * requests per link, a few at a time: point it at the production site outside peak hours.
 */

import { writeFileSync } from 'node:fs';

const args = process.argv.slice(2);
const flag = (name) => {
  const i = args.indexOf(name);
  if (i === -1) return null;
  const value = args[i + 1];
  args.splice(i, value && !value.startsWith('--') ? 2 : 1);
  return value && !value.startsWith('--') ? value : true;
};

const jsonOut = flag('--json');
const concurrency = Math.max(1, Number(flag('--concurrency')) || 4);
const llmsPath = '/llms.txt';
const base = (args[0] || '').replace(/\/+$/, '');

if (!/^https?:\/\//i.test(base)) {
  console.error('Usage: node tools/verify-llms.mjs <https://site> [--json report.json] [--concurrency 4]');
  process.exit(2);
}

const UA = 'AI-Friendly-verify/1.0 (+llms.txt link checker)';

async function head(url, method = 'GET') {
  const res = await fetch(url, { method, redirect: 'manual', headers: { 'user-agent': UA, accept: 'text/html,text/markdown,*/*' }, signal: AbortSignal.timeout(30000) });
  return res;
}

/** Where a chain of redirects ends, up to six hops. */
async function finalUrl(url) {
  let current = url;
  for (let hop = 0; hop < 6; hop++) {
    const res = await head(current);
    const location = res.headers.get('location');
    if (res.status >= 300 && res.status < 400 && location) {
      current = new URL(location, current).href;
      continue;
    }
    return { url: current, status: res.status };
  }
  return { url: current, status: 'loop' };
}

const htmlAddress = (mdUrl) => mdUrl.replace(/\/index\.html\.md$/i, '/').replace(/\.md$/i, '');
const normalize = (u) => { try { const x = new URL(u); return (x.origin + x.pathname.replace(/\/+$/, '') + x.search).toLowerCase(); } catch { return u; } };
const technical = (u) => { try { return /\/\d+-[^/]+(\.html?)?$/i.test(new URL(u).pathname); } catch { return false; } };

async function check(entry) {
  const row = { title: entry.title, markdown: entry.url, html: htmlAddress(entry.url), problems: [] };

  try {
    const md = await head(entry.url);
    row.mdStatus = md.status;
    if (md.status !== 200) {
      row.problems.push('markdown ' + md.status);
      const to = md.headers.get('location');
      if (to) row.mdRedirect = new URL(to, entry.url).href;
    }

    const page = await head(row.html);
    row.htmlStatus = page.status;
    const robotsHeader = page.headers.get('x-robots-tag') || '';
    row.noindex = /noindex/i.test(robotsHeader);

    if (page.status >= 300 && page.status < 400) {
      row.htmlRedirect = new URL(page.headers.get('location') || '', row.html).href;
      row.problems.push('html ' + page.status + ' -> ' + row.htmlRedirect);
    } else if (page.status === 404 || page.status >= 400) {
      row.problems.push('html ' + page.status);
      const end = await finalUrl(row.html).catch(() => null);
      if (end) row.finalUrl = end.url;
    } else if (page.status === 200) {
      const body = await page.text();
      const meta = body.match(/<meta[^>]+name=["']robots["'][^>]*>/i);
      if (meta && /noindex/i.test(meta[0])) row.noindex = true;
      const canonical = body.match(/<link[^>]+rel=["']canonical["'][^>]*>/i);
      const href = canonical && canonical[0].match(/href=["']([^"']+)["']/i);
      row.canonical = href ? new URL(href[1].replace(/&amp;/g, '&'), row.html).href : '';
      if (row.canonical && normalize(row.canonical) !== normalize(row.html)) {
        row.problems.push('canonical is ' + row.canonical);
      }
    }

    if (row.noindex) row.problems.push('noindex');
    if (technical(row.html)) row.problems.push('technical-looking path');
  } catch (error) {
    row.problems.push('error: ' + (error.cause?.code || error.message));
  }

  return row;
}

const response = await head(base + llmsPath).catch((error) => ({ status: 0, error }));
if (response.status !== 200) {
  console.error(`Cannot read ${base}${llmsPath}: ` + (response.status || response.error?.message));
  process.exit(2);
}

const text = await response.text();
const links = [...text.matchAll(/^\s*-\s*\[([^\]]*)\]\((\S+?)\)/gm)].map((m) => ({ title: m[1], url: m[2] }));
const unique = [...new Map(links.map((l) => [l.url, l])).values()];

if (!unique.length) {
  console.error('llms.txt has no links to check.');
  process.exit(2);
}

const rows = [];
let next = 0;
await Promise.all(Array.from({ length: Math.min(concurrency, unique.length) }, async () => {
  while (next < unique.length) rows.push(await check(unique[next++]));
}));
rows.sort((a, b) => links.findIndex((l) => l.url === a.markdown) - links.findIndex((l) => l.url === b.markdown));

const pad = (v, n) => String(v ?? '').padEnd(n).slice(0, n);
console.log(`${base}/llms.txt: ${links.length} links, ${unique.length} distinct\n`);
console.log(pad('MD', 4), pad('HTML', 5), pad('noindex', 8), 'URL');
for (const r of rows) {
  console.log(pad(r.mdStatus, 4), pad(r.htmlStatus, 5), pad(r.noindex ? 'yes' : '', 8), r.markdown);
  for (const p of r.problems) console.log('      ! ' + p);
  if (r.finalUrl && r.finalUrl !== r.html) console.log('      ~ ends at ' + r.finalUrl);
}

const bad = rows.filter((r) => r.problems.length);
const count = (fn) => rows.filter(fn).length;
console.log(`\nclean: ${rows.length - bad.length}   with problems: ${bad.length}`);
console.log(`markdown not 200: ${count((r) => r.mdStatus !== 200)}   html redirect: ${count((r) => r.htmlRedirect)}   html 4xx/5xx: ${count((r) => r.htmlStatus >= 400)}   noindex: ${count((r) => r.noindex)}   technical path: ${count((r) => technical(r.html))}`);

if (typeof jsonOut === 'string') {
  writeFileSync(jsonOut, JSON.stringify({ site: base, checkedAt: new Date().toISOString(), rows }, null, 2));
  console.log('report written to ' + jsonOut);
}

process.exit(bad.length ? 1 : 0);
