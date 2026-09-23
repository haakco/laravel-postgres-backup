#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" != "" && "${1:-}" != "--dry-run" ]]; then
  echo "Usage: $0 [--dry-run]" >&2
  exit 2
fi

if [[ "$(git branch --show-current)" != "main" ]]; then
  echo "Release must run from main." >&2
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Commit or remove local changes before releasing." >&2
  exit 1
fi

local_head=$(git rev-parse HEAD)
remote_head=$(git ls-remote origin refs/heads/main | awk '{print $1}')
if [[ -z "$remote_head" || "$local_head" != "$remote_head" ]]; then
  echo "Local main must match origin/main before releasing." >&2
  exit 1
fi

latest=$(git ls-remote --tags --refs origin \
  | sed -nE 's#.*refs/tags/v([0-9]+)\.([0-9]+)\.([0-9]+)$#\1.\2.\3#p' \
  | sort -t. -k1,1n -k2,2n -k3,3n \
  | tail -n 1)

if [[ -z "$latest" ]]; then
  next="v0.1.0"
else
  IFS=. read -r major minor patch <<< "$latest"
  next="v${major}.${minor}.$((patch + 1))"
fi

echo "Next release: $next"
if [[ "${1:-}" == "--dry-run" ]]; then
  exit 0
fi

git tag "$next"
git push origin "$next"
gh release create "$next" --verify-tag --generate-notes
