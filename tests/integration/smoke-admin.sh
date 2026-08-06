#!/bin/sh
set -eu

base_url="${1:-http://127.0.0.1}"
username="${2:-admin}"
password="${3:?usage: smoke-admin.sh <base-url> <username> <password>}"

curl -sS -c /tmp/aif-cookies.txt "$base_url/administrator/index.php" -o /tmp/aif-login.html
token="$(sed -n 's/.*name="\([a-f0-9]\{32\}\)" value="1".*/\1/p' /tmp/aif-login.html | head -n 1)"
test -n "$token"

curl -sS -b /tmp/aif-cookies.txt -c /tmp/aif-cookies.txt \
  --data-urlencode "username=$username" \
  --data-urlencode "passwd=$password" \
  --data-urlencode "option=com_login" \
  --data-urlencode "task=login" \
  --data-urlencode "return=aW5kZXgucGhwP29wdGlvbj1jb21fYWlmcmllbmRseQ==" \
  --data-urlencode "$token=1" \
  "$base_url/administrator/index.php" -o /tmp/aif-login-result.html

curl -sS -D /tmp/aif-hub.headers -b /tmp/aif-cookies.txt \
  "$base_url/administrator/index.php?option=com_aifriendly" -o /tmp/aif-hub.html

head -n 1 /tmp/aif-hub.headers
grep -q "AI Content Hub" /tmp/aif-hub.html
if grep -qiE "fatal error|uncaught|deprecated" /tmp/aif-hub.html; then
  grep -iE "fatal error|uncaught|deprecated" /tmp/aif-hub.html
  exit 1
fi

echo "AI Friendly administrator Hub rendered successfully"

token="$(sed -n 's/.*name="\([a-f0-9]\{32\}\)" value="1".*/\1/p' /tmp/aif-hub.html | head -n 1)"
test -n "$token"

for action in overview content snapshots events regenerate; do
  curl -sS -b /tmp/aif-cookies.txt \
    --data-urlencode "$token=1" \
    "$base_url/administrator/index.php?option=com_aifriendly&format=json&task=api.$action" \
    -o "/tmp/aif-api-$action.json"
  grep -q '"success":true' "/tmp/aif-api-$action.json"
done

curl -sS -b /tmp/aif-cookies.txt \
  --data-urlencode "$token=1" \
  --data-urlencode "content=# Smoke test" \
  "$base_url/administrator/index.php?option=com_aifriendly&format=json&task=api.preview" \
  -o /tmp/aif-api-preview.json
grep -q '"success":true' /tmp/aif-api-preview.json

echo "AI Friendly JSON controllers responded successfully"
