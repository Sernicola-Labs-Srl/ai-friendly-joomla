#!/bin/sh
set -eu

base_url="${1:-http://127.0.0.1}"
token="$(sed -n 's/.*name="\([a-f0-9]\{32\}\)" value="1".*/\1/p' /tmp/aif-hub.html | head -n 1)"
test -n "$token"

curl -sS -b /tmp/aif-cookies.txt \
  --data-urlencode "$token=1" \
  --data-urlencode "options[schema_enabled]=1" \
  --data-urlencode "options[schema_mode]=standalone" \
  --data-urlencode "options[schema_name]=AI Friendly Test" \
  "$base_url/administrator/index.php?option=com_aifriendly&format=json&task=api.save" \
  -o /tmp/aif-schema-save.json
grep -q '"success":true' /tmp/aif-schema-save.json

curl -sS "$base_url/index.php" -o /tmp/aif-schema-page.html
grep -q 'type="application/ld+json"' /tmp/aif-schema-page.html
grep -q 'AI Friendly Test' /tmp/aif-schema-page.html

echo "AI Friendly standalone Schema rendered successfully"
