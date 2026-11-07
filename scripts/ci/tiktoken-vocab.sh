#!/usr/bin/env bash
# Puts tiktoken's cl100k_base vocab file in TIKTOKEN_CACHE_DIR (plan 22-29).
#
# Some Phase 22 Kernel tests (AiUsageCeilingTest, for one) index content,
# and the ai module chunks text with yethee/tiktoken. tiktoken loads its
# vocab from openaipublic.blob.core.windows.net the first time, unless the
# file is already in its cache dir. This is a tokenizer data file, not a
# model call. Fetching it ahead of the tests lets them run with no network.
#
# The cache file name is sha1 of the URL, and the sha256 is the one
# yethee/tiktoken pins in EncoderProvider.php, so the library accepts it.
# The script exits 1 if the download doesn't match.
#
# Usage: TIKTOKEN_CACHE_DIR=/path bash scripts/ci/tiktoken-vocab.sh
set -euo pipefail

: "${TIKTOKEN_CACHE_DIR:?set TIKTOKEN_CACHE_DIR}"
URL=https://openaipublic.blob.core.windows.net/encodings/cl100k_base.tiktoken
SHA256=223921b76ee99bde995b7ff738513eef100fb51d18c93597a113bcffe865b2a7

mkdir -p "$TIKTOKEN_CACHE_DIR"
FILE="$TIKTOKEN_CACHE_DIR/$(printf %s "$URL" | sha1sum | cut -d' ' -f1)"

if [ -f "$FILE" ] && echo "$SHA256  $FILE" | sha256sum -c --status; then
  echo "tiktoken-vocab: cached copy OK ($FILE)"
  exit 0
fi

curl -sSf --retry 3 -o "$FILE.part" "$URL"
if ! echo "$SHA256  $FILE.part" | sha256sum -c --status; then
  rm -f "$FILE.part"
  echo "tiktoken-vocab: sha256 mismatch for $URL" >&2
  exit 1
fi
mv "$FILE.part" "$FILE"
echo "tiktoken-vocab: fetched and verified ($FILE)"
