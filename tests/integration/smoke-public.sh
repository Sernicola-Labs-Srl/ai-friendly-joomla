#!/bin/sh
set -eu

base_url="${1:-http://127.0.0.1}"
public_alias="${2:-public-useful}"
curl -fsS "$base_url/llms.txt" -o /tmp/aif-llms.txt
if [ "$public_alias" = public-useful ]; then
  grep -q 'Public & Useful' /tmp/aif-llms.txt
else
  grep -q 'Second public page' /tmp/aif-llms.txt
fi

public_code="$(curl -sS -o /tmp/aif-public.md -w '%{http_code}' "$base_url/index.php/component/content/article/$public_alias.md?catid=2&lang=*")"
test "$public_code" = 200
if [ "$public_alias" = public-useful ]; then
  grep -q 'Readable public text' /tmp/aif-public.md
else
  grep -q 'Second public text' /tmp/aif-public.md
fi

private_code="$(curl -sS -o /tmp/aif-private.md -w '%{http_code}' "$base_url/index.php/component/content/article/private-article.md?catid=2&lang=*")"
test "$private_code" = 404

future_code="$(curl -sS -o /tmp/aif-future.md -w '%{http_code}' "$base_url/index.php/component/content/article/future-article.md?catid=2&lang=*")"
test "$future_code" = 404
echo 'Public Markdown 200; restricted and future Markdown 404; llms.txt decoded'
