#!/usr/bin/env bash
# Split monorepo path prefixes with splitsh-lite and push to satellite GitHub repos.
# Usage:
#   ./bin/splitsh-publish.sh
#       Split current HEAD (typically main) and push each split SHA to refs/heads/$SPLITSH_TARGET_BRANCH.
#   ./bin/splitsh-publish.sh tag <tag>
#       Checkout <tag>, split each prefix, push each split SHA to refs/tags/<tag> on satellites.
# Environment:
#   SPLITSH_PUSH_TOKEN — GitHub PAT with contents:write on each satellite (optional; dry-run if unset).
#   SPLITSH_GITHUB_ORG — GitHub org or user (default: gplanchat).
#   SPLITSH_TARGET_BRANCH — Satellite default branch (default: main).
#   SPLITSH_LITE — Path to splitsh-lite binary (default: splitsh-lite on PATH).
#   SPLITSH_FORCE — If 1, branch push uses --force (dangerous).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

SPLITSH_LITE="${SPLITSH_LITE:-splitsh-lite}"
TOKEN="${SPLITSH_PUSH_TOKEN:-}"
ORG="${SPLITSH_GITHUB_ORG:-gplanchat}"
BRANCH="${SPLITSH_TARGET_BRANCH:-main}"

# prefix|repo — GitHub repository name under SPLITSH_GITHUB_ORG
SPLITS=(
    "src/Durable/|durable"
    "src/DurableBundle/|durable-bundle"
    "src/Bridge/Temporal/|durable-bridge-temporal"
)

remote_url() {
    local repo="$1"
    if [[ -n "$TOKEN" ]]; then
        printf 'https://x-access-token:%s@github.com/%s/%s.git' "$TOKEN" "$ORG" "$repo"
    else
        printf ''
    fi
}

split_sha() {
    local prefix="$1"
    local out ec
    set +e
    # splitsh/lite prints progress on stderr and the split HEAD hash on stdout; merge streams for robust parsing.
    out="$("$SPLITSH_LITE" --prefix="$prefix" 2>&1)"
    ec=$?
    set -e
    if [[ $ec -ne 0 ]]; then
        echo "splitsh-lite failed (exit $ec) for prefix $prefix" >&2
        printf '%s\n' "$out" >&2
        exit 1
    fi
    local sha
    sha="$(printf '%s\n' "$out" | grep -oE '[a-f0-9]{40}' | tail -n1)"
    if [[ ! "$sha" =~ ^[a-f0-9]{40}$ ]]; then
        echo "Could not parse split SHA from splitsh-lite for prefix $prefix" >&2
        printf '%s\n' "$out" >&2
        exit 1
    fi
    printf '%s' "$sha"
}

require_clean_tree() {
    if [[ -n "$(git status --porcelain)" ]]; then
        echo "Working tree is not clean; commit or stash before running tag mode." >&2
        exit 1
    fi
}

push_branch_mode() {
    local url sha force_flag=()
    if [[ "${SPLITSH_FORCE:-0}" == "1" ]]; then
        force_flag=(--force)
    fi

    for entry in "${SPLITS[@]}"; do
        IFS='|' read -r prefix repo <<<"$entry"
        sha="$(split_sha "$prefix")"
        url="$(remote_url "$repo")"
        if [[ -z "$url" ]]; then
            echo "[branch] $repo split SHA=$sha (dry-run, set SPLITSH_PUSH_TOKEN to push)"
            continue
        fi
        echo "[branch] Pushing $ORG/$repo $sha -> refs/heads/$BRANCH"
        git push "${force_flag[@]}" "$url" "$sha:refs/heads/$BRANCH"
    done
}

push_tag_mode() {
    local tag="$1"
    local url sha

    if [[ -z "$tag" ]]; then
        echo "usage: $0 tag <tag>" >&2
        exit 1
    fi

    if [[ -z "${GITHUB_ACTIONS:-}" ]]; then
        require_clean_tree
    fi
    git checkout -q "$tag"

    for entry in "${SPLITS[@]}"; do
        IFS='|' read -r prefix repo <<<"$entry"
        sha="$(split_sha "$prefix")"
        url="$(remote_url "$repo")"
        if [[ -z "$url" ]]; then
            echo "[tag] $repo split SHA=$sha for $tag (dry-run, set SPLITSH_PUSH_TOKEN to push)"
            continue
        fi
        echo "[tag] Pushing $ORG/$repo $sha -> refs/tags/$tag"
        # Create / update the tag on the satellite to point at the split commit for this release.
        git push "$url" "$sha:refs/tags/$tag"
    done
}

if [[ "${1:-}" == "tag" ]]; then
    push_tag_mode "${2:-}"
else
    push_branch_mode
fi
