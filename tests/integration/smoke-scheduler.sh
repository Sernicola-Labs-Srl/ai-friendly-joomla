#!/bin/sh
set -eu

task_id="${1:?usage: smoke-scheduler.sh <task-id> <content-key>}"
content_key="${2:?usage: smoke-scheduler.sh <task-id> <content-key>}"
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

api toggle --data-urlencode "key=$content_key" >/tmp/aif-toggle.json
if grep -q '"excluded":false' /tmp/aif-toggle.json; then
  api toggle --data-urlencode "key=$content_key" >/tmp/aif-toggle.json
fi
grep -q '"excluded":true' /tmp/aif-toggle.json

before="$(api regenerationStatus | field id)"
attempt=0
while :; do
  php cli/joomla.php scheduler:run --id="$task_id" >/tmp/aif-scheduler-run.txt
  state="$(api regenerationStatus)"
  id="$(printf '%s' "$state" | field id)"
  status="$(printf '%s' "$state" | field status)"
  attempt=$((attempt + 1))
  test "$attempt" -le 10
  if [ "$id" != "$before" ] && [ "$status" = complete ]; then break; fi
  if [ "$status" = failed ]; then printf '%s\n' "$state"; exit 1; fi
done

curl -fsS http://127.0.0.1/llms-full.txt -o /tmp/aif-scheduler-corpus.txt
if grep -q 'Public heading' /tmp/aif-scheduler-corpus.txt; then
  echo 'Excluded article still exists in corpus after Scheduler run' >&2
  exit 1
fi
grep -q 'Second public page' /tmp/aif-scheduler-corpus.txt
echo "Scheduler refreshed corpus in $attempt executions"
