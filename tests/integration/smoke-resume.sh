#!/bin/sh
set -eu

token="$(sed -n 's/.*name="\([a-f0-9]\{32\}\)" value="1".*/\1/p' /tmp/aif-hub.html | head -n 1)"
test -n "$token"
api() {
  action="$1"
  curl -fsS -b /tmp/aif-cookies.txt --data-urlencode "$token=1" \
    "http://127.0.0.1/administrator/index.php?option=com_aifriendly&format=json&task=api.$action"
}
field() {
  php -r '$data=json_decode(file_get_contents("php://stdin"), true); echo $data["data"][$argv[1]] ?? "";' "$1"
}

started="$(api regenerationStart)"
id="$(printf '%s' "$started" | field id)"
test -n "$id"
test "$(printf '%s' "$started" | field status)" = running
first="$(api regenerationStep)"
test "$(printf '%s' "$first" | field cursor)" -eq 1

temporary="/var/www/html/.aifriendly-llms-full-$id.tmp"
test -f "$temporary"
printf '%s' 'UNCOMMITTED-SENTINEL' >>"$temporary"

attempt=0
while :; do
  state="$(api regenerationStep)"
  status="$(printf '%s' "$state" | field status)"
  attempt=$((attempt + 1))
  test "$attempt" -le 10
  if [ "$status" = complete ]; then break; fi
  if [ "$status" = failed ]; then printf '%s\n' "$state"; exit 1; fi
done

curl -fsS http://127.0.0.1/llms-full.txt -o /tmp/aif-resumed-corpus.txt
if grep -q 'UNCOMMITTED-SENTINEL' /tmp/aif-resumed-corpus.txt; then
  echo 'Interrupted append leaked into the published corpus' >&2
  exit 1
fi
echo "Corpus recovered after interrupted append: $id"
