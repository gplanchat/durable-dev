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

# Push using Authorization: Basic so the credential helper from CI (GITHUB_TOKEN) cannot override
# pushes to other repositories — embed-only URLs are sometimes ignored when a global helper matches github.com.
git_push_satellite() {
    local repo="$1"
    local refspec="$2"
    shift 2
    local url="https://github.com/${ORG}/${repo}.git"
    local basic
    if [[ -z "$TOKEN" ]]; then
        return 0
    fi
    basic="$(printf 'x-access-token:%s' "$TOKEN" | base64 -w0 2>/dev/null || printf 'x-access-token:%s' "$TOKEN" | base64 | tr -d '\n')"
    GIT_TERMINAL_PROMPT=0 git \
        -c "http.extraHeader=Authorization: Basic ${basic}" \
        push "$@" "$url" "$refspec"
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
    local sha force_flag=()
    if [[ "${SPLITSH_FORCE:-0}" == "1" ]]; then
        force_flag=(--force)
    fi

    for entry in "${SPLITS[@]}"; do
        IFS='|' read -r prefix repo <<<"$entry"
        sha="$(split_sha "$prefix")"
        if [[ -z "$TOKEN" ]]; then
            echo "[branch] $repo split SHA=$sha (dry-run, set SPLITSH_PUSH_TOKEN to push)"
            continue
        fi
        echo "[branch] Pushing $ORG/$repo $sha -> refs/heads/$BRANCH"
        git_push_satellite "$repo" "$sha:refs/heads/$BRANCH" "${force_flag[@]}"
    done
}

push_tag_mode() {
    local tag="$1"
    local sha

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
        if [[ -z "$TOKEN" ]]; then
            echo "[tag] $repo split SHA=$sha for $tag (dry-run, set SPLITSH_PUSH_TOKEN to push)"
            continue
        fi
        echo "[tag] Pushing $ORG/$repo $sha -> refs/tags/$tag"
        git_push_satellite "$repo" "$sha:refs/tags/$tag"
    done
}

if [[ "${1:-}" == "tag" ]]; then
    push_tag_mode "${2:-}"
else
    push_branch_mode
fi
