#!/bin/sh
set -eu

user_id="${1:?usage: smoke-author.sh <user-id>}"
base_url="${2:-http://127.0.0.1}"
cookie=/tmp/aif-cookies.txt
hub=/tmp/aif-hub.html
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
finish_job() {
  attempts=0
  while [ "$(api regenerationStatus | field status)" = running ]; do
    api regenerationStep >/dev/null
    attempts=$((attempts + 1))
    test "$attempts" -le 100
  done
  test "$(api regenerationStatus | field status)" = complete
}

api save --data-urlencode 'options[static_md_files]=1' \
  --data-urlencode 'options[dynamic_cache_ttl]=600' \
  --data-urlencode 'options[regenerate_batch_size]=1' >/dev/null
finish_job
api regenerationStart >/dev/null
finish_job

article_url="$base_url/index.php/component/content/article/public-useful.md?catid=2&lang=*"
curl -fsS "$article_url" -o /tmp/aif-author-before.md
grep -q 'author: PHP81 Admin' /tmp/aif-author-before.md

curl -fsSL -b "$cookie" \
  "$base_url/administrator/index.php?option=com_users&view=user&layout=edit&id=$user_id" \
  -o /tmp/aif-user-edit.html
user_token="$(sed -n 's/.*name="\([a-f0-9]\{32\}\)" value="1".*/\1/p' /tmp/aif-user-edit.html | tail -n 1)"
test -n "$user_token"

curl -sS -D /tmp/aif-user-save.headers -o /tmp/aif-user-save.html -b "$cookie" -c "$cookie" \
  --data-urlencode 'option=com_users' \
  --data-urlencode 'task=user.apply' \
  --data-urlencode 'jform[name]=PHP81 Renamed Admin' \
  --data-urlencode 'jform[username]=admin' \
  --data-urlencode 'jform[email]=php81@example.test' \
  --data-urlencode "jform[id]=$user_id" \
  --data-urlencode 'jform[groups][]=8' \
  --data-urlencode 'jform[sendEmail]=1' \
  --data-urlencode 'jform[block]=0' \
  --data-urlencode "$user_token=1" \
  "$base_url/administrator/index.php?option=com_users&layout=edit&id=$user_id" >/dev/null

status="$(api regenerationStatus)"
test "$(printf '%s' "$status" | field status)" = running
finish_job
curl -fsS "$article_url" -o /tmp/aif-author-after.md
grep -q 'author: PHP81 Renamed Admin' /tmp/aif-author-after.md
if grep -q 'author: PHP81 Admin' /tmp/aif-author-after.md; then
  echo 'Old author name remains in Markdown' >&2
  exit 1
fi
echo 'Author rename refreshed static Markdown and invalidated the response cache'
