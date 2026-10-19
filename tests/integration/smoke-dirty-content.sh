#!/bin/sh
set -eu

article_id="${1:?usage: smoke-dirty-content.sh <article-id>}"
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
finish_job() {
  attempt=0
  while [ "$(api regenerationStatus | field status)" = running ]; do
    api regenerationStep >/dev/null
    attempt=$((attempt + 1))
    test "$attempt" -le 100
  done
  test "$(api regenerationStatus | field status)" = complete
}

finish_job
started="$(api regenerationStart)"
first_id="$(printf '%s' "$started" | field id)"
test "$(printf '%s' "$started" | field status)" = running
api regenerationStep >/dev/null

curl -fsSL -b /tmp/aif-cookies.txt \
  "http://127.0.0.1/administrator/index.php?option=com_content&task=article.edit&id=$article_id" \
  -o /tmp/aif-article-edit.html
edit_token="$(sed -n 's/.*name="\([a-f0-9]\{32\}\)" value="1".*/\1/p' /tmp/aif-article-edit.html | tail -n 1)"
test -n "$edit_token"

curl -sS -D /tmp/aif-article-save.headers -o /tmp/aif-article-save.html \
  -b /tmp/aif-cookies.txt -c /tmp/aif-cookies.txt \
  --data-urlencode 'option=com_content' \
  --data-urlencode 'task=article.apply' \
  --data-urlencode 'jform[title]=Public & Useful' \
  --data-urlencode 'jform[alias]=public-useful' \
  --data-urlencode 'jform[articletext]=<h2>Public heading</h2><p>Readable public text. Edited during the job.</p>' \
  --data-urlencode 'jform[state]=1' \
  --data-urlencode 'jform[catid]=2' \
  --data-urlencode 'jform[access]=1' \
  --data-urlencode 'jform[language]=*' \
  --data-urlencode "jform[id]=$article_id" \
  --data-urlencode "$edit_token=1" \
  "http://127.0.0.1/administrator/index.php?option=com_content&layout=edit&id=$article_id" >/dev/null

job="$(api regenerationStatus)"
test "$(printf '%s' "$job" | field id)" = "$first_id"
test "$(printf '%s' "$job" | field dirty)" = 1
finish_job

second="$(api regenerationStart)"
test "$(printf '%s' "$second" | field id)" != "$first_id"
finish_job
curl -fsS http://127.0.0.1/llms-full.txt -o /tmp/aif-dirty-corpus.txt
grep -q 'Edited during the job' /tmp/aif-dirty-corpus.txt
echo 'Article edit marked the running job dirty and the next pass published the edit'
