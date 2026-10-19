#!/bin/sh
set -eu

base_url="${1:-http://127.0.0.1}"
cookie="${2:-/tmp/aif-cookies.txt}"
hub="${3:-/tmp/aif-hub.html}"
token="$(sed -n 's/.*name="\([a-f0-9]\{32\}\)" value="1".*/\1/p' "$hub" | head -n 1)"
test -n "$token"

api() {
  action="$1"
  shift
  curl -fsS -b "$cookie" --data-urlencode "$token=1" "$@" \
    "$base_url/administrator/index.php?option=com_aifriendly&format=json&task=api.$action"
}

field() {
  php -r '$data=json_decode(file_get_contents("php://stdin"), true); echo $data["data"][$argv[1]] ?? "";' "$1"
}

preflight=0
while [ "$(api regenerationStatus | field status)" = running ]; do
  api regenerationStep >/dev/null
  preflight=$((preflight + 1))
  test "$preflight" -le 100
done

api save --data-urlencode 'options[llms_full_enabled]=1' \
  --data-urlencode 'options[regenerate_batch_size]=1' >/tmp/aif-save.json
grep -q '"success":true' /tmp/aif-save.json

first="$(api regenerationStart)"
printf '%s' "$first" | grep -q '"success":true'
job_id="$(printf '%s' "$first" | field id)"
test -n "$job_id"
second="$(api regenerationStart)"
test "$(printf '%s' "$second" | field id)" = "$job_id"

cursor=0
steps=0
while :; do
  state="$(api regenerationStep)"
  status="$(printf '%s' "$state" | field status)"
  next_cursor="$(printf '%s' "$state" | field cursor)"
  test "$next_cursor" -ge "$cursor"
  test "$((next_cursor - cursor))" -le 1
  cursor="$next_cursor"
  steps=$((steps + 1))
  test "$steps" -le 100
  if [ "$status" = complete ]; then break; fi
  if [ "$status" = failed ]; then printf '%s\n' "$state"; exit 1; fi
done

test "$(api regenerationStatus | field id)" = "$job_id"
curl -fsS "$base_url/llms-full.txt" -o /tmp/aif-full-corpus.txt
grep -q 'Public heading' /tmp/aif-full-corpus.txt
if grep -q 'Private secret fixture\|Future fixture' /tmp/aif-full-corpus.txt; then
  echo 'Excluded content leaked into full corpus' >&2
  exit 1
fi
echo "Regeneration completed: $job_id, $steps bounded steps, $cursor items"
