#!/bin/sh
set -eu

content_key="${1:?usage: smoke-corpus-atomic.sh <content-key>}"
token="$(sed -n 's/.*name="\([a-f0-9]\{32\}\)" value="1".*/\1/p' /tmp/aif-hub.html | head -n 1)"
test -n "$token"
api() {
  action="$1"
  shift
  curl -fsS -b /tmp/aif-cookies.txt --data-urlencode "$token=1" "$@" \
    "http://127.0.0.1/administrator/index.php?option=com_aifriendly&format=json&task=api.$action"
}
field() {
  php -r '$data=json_decode(file_get_contents("php://stdin"), true); echo $data["data"][$argv[1]] ?? "";' "$1"
}

target=/var/www/html/llms-full.txt
before="$(sha256sum "$target" | cut -d ' ' -f 1)"
toggle="$(api toggle --data-urlencode "key=$content_key")"
test "$(printf '%s' "$toggle" | field excluded)" = 1 || \
  printf '%s' "$toggle" | grep -q '"excluded":true'

job="$(api regenerationStart)"
test "$(printf '%s' "$job" | field status)" = running
test "$before" = "$(sha256sum "$target" | cut -d ' ' -f 1)"

attempt=0
while :; do
  job="$(api regenerationStep)"
  status="$(printf '%s' "$job" | field status)"
  attempt=$((attempt + 1))
  test "$attempt" -le 100
  if [ "$status" = complete ]; then break; fi
  if [ "$status" = failed ]; then printf '%s\n' "$job"; exit 1; fi
  test "$before" = "$(sha256sum "$target" | cut -d ' ' -f 1)"
done

after="$(sha256sum "$target" | cut -d ' ' -f 1)"
test "$after" != "$before"
echo 'Corpus remained unchanged during the job and changed only at completion'
