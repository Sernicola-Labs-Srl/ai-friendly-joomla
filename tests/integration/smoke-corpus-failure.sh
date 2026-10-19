#!/bin/sh
set -eu

base_url="${1:-http://127.0.0.1}"
token="$(sed -n 's/.*name="\([a-f0-9]\{32\}\)" value="1".*/\1/p' /tmp/aif-hub.html | head -n 1)"
test -n "$token"
target=/var/www/html/llms-full.txt
test -f "$target"
before="$(sha256sum "$target" | cut -d ' ' -f 1)"
original_mode="$(stat -c %a /var/www/html)"
trap 'chmod "$original_mode" /var/www/html' EXIT
chmod a-w /var/www/html

result="$(curl -fsS -b /tmp/aif-cookies.txt --data-urlencode "$token=1" \
  "$base_url/administrator/index.php?option=com_aifriendly&format=json&task=api.regenerationStart")"
status="$(printf '%s' "$result" | php -r '$data=json_decode(file_get_contents("php://stdin"), true); echo $data["data"]["status"] ?? "";')"
test "$status" = failed
test "$before" = "$(sha256sum "$target" | cut -d ' ' -f 1)"
curl -fsS "$base_url/llms-full.txt" >/dev/null
echo 'Write failure retained the previous public corpus'
