#!/bin/sh
# Checks the public-address, description and event-venue behaviour of AI Friendly 2.7.
#
# Needs a site prepared with tests/integration/fixtures-2.7.sql and the fake forSEF
# plugin in tests/integration/fake-forsef (see tests/integration/README.md), with
# SEF rewrite and suffix on, and the event options set as documented there.
#
#   sh tests/integration/smoke-public-urls.sh http://localhost:8089
set -u

base="${1:-http://localhost:8089}"
fail=0

code() { curl -s -o /dev/null -w '%{http_code}' "$1"; }
location() { curl -s -o /dev/null -w '%{redirect_url}' "$1"; }
check() {
  if [ "$2" = "$3" ]; then echo "ok    $1"; else echo "FAIL  $1: expected '$3', got '$2'"; fail=1; fi
}
contains() {
  if printf '%s' "$2" | grep -qF -- "$3"; then echo "ok    $1"; else echo "FAIL  $1: '$3' not found"; fail=1; fi
}
lacks() {
  if printf '%s' "$2" | grep -qF -- "$3"; then echo "FAIL  $1: '$3' is present"; fail=1; else echo "ok    $1"; fi
}

llms="$(curl -s "$base/llms.txt")"

echo "-- llms.txt addresses"
contains "public address of a course" "$llms" "$base/corsi/corso-di-musica-per-adulti.html.md"
contains "public address of an initiative" "$llms" "$base/tutte-le-iniziative/concerto-teatro-pime-2026.html.md"
lacks "no technical path with an id" "$llms" "/8-corsi-di-musica-per-adulti"
lacks "no technical path in the initiatives" "$llms" "/11-iniziative-2026/"

echo "-- every link answers 200 on the HTML page and on the .md"
for md in $(printf '%s' "$llms" | grep -o '(http[^)]*\.md)' | tr -d '()'); do
  check "md   $md" "$(code "$md")" 200
  check "html ${md%.md}" "$(code "${md%.md}")" 200
done

echo "-- noindex and empty pages are not listed or served"
lacks "article with robots noindex" "$llms" "pagina-noindex"
lacks "article of a noindex category" "$llms" "glossario/yoga"
check "article with robots noindex .md" "$(code "$base/pagina-noindex.html.md")" 404
check "article of a noindex category .md" "$(code "$base/glossario/yoga.html.md")" 404
check "empty noindex category .md" "$(code "$base/lettera-z.md")" 404
check "noindex category with an article .md" "$(code "$base/lettera-y.md")" 404
check "parent category whose entries are in a child .md" "$(code "$base/glossario.md")" 200
check "category with articles .md" "$(code "$base/corsi.md")" 200

echo "-- .md addresses"
check "public .md" "$(code "$base/corsi/corso-di-musica-per-adulti.html.md")" 200
check "public .md without the suffix" "$(code "$base/corsi/corso-di-musica-per-adulti.md")" 200
check "technical .md is a 301" "$(code "$base/corsi/8-corsi-di-musica-per-adulti.html.md")" 301
check "technical .md goes to the public one" "$(location "$base/corsi/8-corsi-di-musica-per-adulti.html.md")" "$base/corsi/corso-di-musica-per-adulti.html.md"
check "technical .md of an article outside a menu" "$(location "$base/component/content/article/14-chi-siamo.html.md")" "$base/chi-siamo.html.md"
check "unknown .md stays 404" "$(code "$base/non-esiste.html.md")" 404
check "HTML technical path is the site's own 404" "$(code "$base/corsi/8-corsi-di-musica-per-adulti.html")" 404

echo "-- descriptions"
contains "meta description wins" "$llms" "Il concerto di primavera degli allievi di Cluster al Teatro PIME di Milano."
contains "4SEO custom description" "$llms" "Laboratori di canto corale a Milano per adulti e ragazzi"
contains "excerpt skips titles and buttons" "$llms" "Dal 1999 accompagniamo gli adulti di ogni livello"
lacks "no layout css in a description" "$llms" ".el-button"
lacks "no glued words" "$llms" "CLUSTERGRAPPOLO"
lacks "no shouted title as a description" "$llms" ": SCUOLA DI MUSICA CLUSTER"
lacks "a short line is not a description" "$llms" "Giovedì 14 maggio 2026 dalle 18 alle 19:30Un incontro"
md="$(curl -s "$base/corsi/corso-di-musica-per-adulti.html.md")"
contains "heading words kept apart in the .md" "$md" "CLUSTER GRAPPOLO GRUPPO"

echo "-- event schema"
ld() {
  curl -s "$1" | tr '\n' ' ' | grep -o '<script type="application/ld+json"[^>]*>[^<]*</script>' | sed 's/<[^>]*>//g'
}
pime="$(ld "$base/tutte-le-iniziative/concerto-teatro-pime-2026.html")"
contains "off-site event venue name" "$pime" '"name":"Teatro PIME"'
contains "off-site event street" "$pime" 'Via Mosè Bianchi 94'
contains "event end date from a time range" "$pime" '"endDate":"2026-04-11T20:00:00+02:00"'
contains "event status" "$pime" '"eventStatus":"https://schema.org/EventScheduled"'
web="$(ld "$base/tutte-le-iniziative/webinar-didattica-musicale.html")"
contains "online event location" "$web" '"@type":"VirtualLocation"'
contains "online attendance mode" "$web" 'OnlineEventAttendanceMode'
rock="$(ld "$base/tutte-le-iniziative/serata-pop-rock.html")"
contains "venue from custom fields" "$rock" '"name":"Hard Rock Cafe"'
contains "end date from a custom field" "$rock" '"endDate":"2026-07-03T23:30:00+02:00"'
saggio="$(ld "$base/tutte-le-iniziative/saggio-di-fine-anno.html")"
contains "event at the school keeps the school place" "$saggio" '"location":{"@id":"'"$base"'/#place"}'

if [ "$fail" -eq 0 ]; then echo; echo "All checks passed."; else echo; echo "Some checks failed."; fi
exit "$fail"
