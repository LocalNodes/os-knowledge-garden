#!/usr/bin/env bash
#
# LocalNodes Image Identity (GHCR registry API, anonymous, read-only)
#
# Usage:
#   ./scripts/ops/image-identity.sh <tag>
#
#   ./scripts/ops/image-identity.sh latest
#   ./scripts/ops/image-identity.sh 8d03591        # a 7-char commit tag pushed by build-image.yml
#
# Prints exactly one line:
#   tag=<tag> index=<sha256:...> amd64=<sha256:...> config=<sha256:...> revision=<40 hex> created=<iso>
#
# Three different digests exist per image, because buildx pushes an OCI image index
# (amd64 manifest + attestation manifest). Compare like with like (20-RESEARCH.md Q2):
#   index   = the tag's docker-content-digest; this is what `docker images --digests` shows
#             (the RepoDigest) and what a `docker compose pull` log prints as "Digest:".
#   amd64   = the linux/amd64 image manifest inside the index.
#   config  = the image config blob; this is what `docker inspect` shows as .Image on a
#             container with the classic image store (with the containerd store, .Image can
#             be the index digest instead).
#   revision = the org.opencontainers.image.revision label = the full git commit SHA built.
#
# No credentials: the package is public, so an anonymous pull token is enough.
#
# Exit status: 0 on success, 2 when the tag does not exist, 3 when the index has no
# linux/amd64 manifest, 1 on any other error (network, parse).
#
# Prerequisites: curl, jq
#
set -euo pipefail

TAG="${1:-}"
if [[ -z "$TAG" ]]; then
  echo "Usage: $0 <tag>" >&2
  exit 1
fi

REPO="localnodes/os-knowledge-garden"
REGISTRY="https://ghcr.io"
ACCEPT="application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json"

TOKEN=$(curl -sS --max-time 30 "$REGISTRY/token?scope=repository:$REPO:pull" | jq -r '.token // empty')
if [[ -z "$TOKEN" ]]; then
  echo "ERROR: could not get an anonymous pull token from ghcr.io" >&2
  exit 1
fi
AUTH="Authorization: Bearer $TOKEN"

# 1. Index digest of the tag (HEAD request).
INDEX=$(curl -sS --max-time 30 -I -H "$AUTH" -H "Accept: $ACCEPT" \
  "$REGISTRY/v2/$REPO/manifests/$TAG" \
  | awk -F': ' 'tolower($1)=="docker-content-digest"{print $2}' | tr -d '\r')
if [[ -z "$INDEX" ]]; then
  echo "ERROR: tag $TAG not found" >&2
  exit 2
fi

# 2. The linux/amd64 manifest inside the index.
AMD64=$(curl -sS --max-time 30 -H "$AUTH" -H "Accept: $ACCEPT" \
  "$REGISTRY/v2/$REPO/manifests/$INDEX" \
  | jq -r '[.manifests[]? | select(.platform.os=="linux" and .platform.architecture=="amd64") | .digest][0] // empty')
if [[ -z "$AMD64" ]]; then
  echo "ERROR: tag $TAG ($INDEX) has no linux/amd64 manifest" >&2
  exit 3
fi

# 3. Config digest from the amd64 manifest.
CONFIG=$(curl -sS --max-time 30 -H "$AUTH" -H "Accept: $ACCEPT" \
  "$REGISTRY/v2/$REPO/manifests/$AMD64" | jq -r '.config.digest // empty')
if [[ -z "$CONFIG" ]]; then
  echo "ERROR: manifest $AMD64 has no config digest" >&2
  exit 1
fi

# 4. Labels from the config blob (the blob URL redirects to storage, so follow it).
LABELS=$(curl -sS --max-time 60 -L -H "$AUTH" "$REGISTRY/v2/$REPO/blobs/$CONFIG")
REVISION=$(jq -r '.config.Labels["org.opencontainers.image.revision"] // empty' <<<"$LABELS")
CREATED=$(jq -r '.config.Labels["org.opencontainers.image.created"] // empty' <<<"$LABELS")

echo "tag=$TAG index=$INDEX amd64=$AMD64 config=$CONFIG revision=${REVISION:-none} created=${CREATED:-none}"
