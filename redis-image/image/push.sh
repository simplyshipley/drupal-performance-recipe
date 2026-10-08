#!/usr/bin/env bash
# Test, build, tag and push the Redis (Valkey) image to Azure Government ACR
# (docs/redis-image.md). Logs in the same way as the OIG sites' bin/docker-auth.
#
#   bash image/push.sh <revision>   1 -> services/perf-redis:valkey-9.1.2-1
#
# Env: ACR_NAME (acrcigie), REPO (services/perf-redis), SKIP_TESTS=1 to skip
# test-image.sh, LOGIN_SERVER (default <ACR_NAME>.azurecr.us; a local registry
# such as localhost:5055 skips the Azure steps, for trying the script).
set -euo pipefail
cd "$(dirname "$0")"

REV="${1:-}"
if [[ ! "$REV" =~ ^[0-9]+$ ]]; then
  echo "Usage: $0 <revision number>, for example: $0 1" >&2
  exit 1
fi

ACR_NAME="${ACR_NAME:-acrcigie}"
LOGIN_SERVER="${LOGIN_SERVER:-$ACR_NAME.azurecr.us}"
REPO="${REPO:-services/perf-redis}"

# Server version from the pinned base image: valkey/valkey:9.1.2-alpine3.24 -> 9.1.2.
BASE=$(sed -n 's/^FROM valkey\/valkey:\([0-9.]*\)-.*/\1/p' Dockerfile)
if [[ -z "$BASE" ]]; then
  echo "Could not read the Valkey version from the Dockerfile FROM line." >&2
  exit 1
fi
VERSION="valkey-$BASE-$REV"
IMAGE="$LOGIN_SERVER/$REPO:$VERSION"

AZURE=""
[[ "$LOGIN_SERVER" == *.azurecr.us ]] && AZURE=1

if [[ -n "$AZURE" ]]; then
  if [[ "$(az cloud show --query name -o tsv)" != "AzureUSGovernment" ]]; then
    echo "Run first: az cloud set --name AzureUSGovernment && az login" >&2
    exit 1
  fi
  # Tags are never reused: a running revision must keep pointing at the same image.
  if az acr repository show-tags --name "$ACR_NAME" --repository "$REPO" -o tsv 2>/dev/null | grep -qx "$VERSION"; then
    echo "$REPO:$VERSION already exists in $ACR_NAME. Use the next revision number." >&2
    exit 1
  fi
fi

if [[ -z "${SKIP_TESTS:-}" ]]; then
  echo "Testing the image (test-image.sh)..."
  bash test-image.sh
fi

# Container Apps runs linux/amd64; a Mac with Apple silicon would otherwise build arm64.
echo "Building $IMAGE for linux/amd64..."
docker build --platform linux/amd64 --tag "$IMAGE" .

if [[ -n "$AZURE" ]]; then
  echo "Authenticating with ACR..."
  TOKEN=$(az acr login -n "$ACR_NAME" --expose-token --output tsv --query accessToken)
  docker login "$LOGIN_SERVER" --username 00000000-0000-0000-0000-000000000000 --password-stdin <<< "$TOKEN"
fi

echo "Pushing $IMAGE..."
docker push "$IMAGE"
echo "Finished. Deploy with the Bicep parameter redisImage=$IMAGE (never a 'latest' tag)."
