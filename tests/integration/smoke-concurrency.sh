#!/bin/sh
set -eu

token="$(sed -n 's/.*name="\([a-f0-9]\{32\}\)" value="1".*/\1/p' /tmp/aif-hub.html | head -n 1)"
test -n "$token"
url='http://127.0.0.1/administrator/index.php?option=com_aifriendly&format=json&task=api.regenerationStart'

i=1
while [ "$i" -le 10 ]; do
  curl -fsS -b /tmp/aif-cookies.txt --data-urlencode "$token=1" "$url" >"/tmp/aif-concurrent-$i.json" &
  i=$((i + 1))
done
wait

i=1
while [ "$i" -le 10 ]; do
  grep -q '"success":true' "/tmp/aif-concurrent-$i.json"
  i=$((i + 1))
done
echo 'Ten concurrent starts returned; check the database for one running job'
