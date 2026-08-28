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
    "src/DurablePlugin/|durable-plugin"
    "src/Bridge/Temporal/|durable-bridge-temporal"
    "src/Bridge/Dbal/|durable-bridge-dbal"
    "src/Bridge/Illuminate/|durable-bridge-illuminate"
    "src/DurableLaravel/|durable-laravel"
    # Le satellite porte déjà un `main` : le split de mars 2026, quand ce préfixe tenait un tout
    # autre module (`Api`, `Model`, une commande de consommation). Il est un ancêtre du split
    # d'aujourd'hui — même préfixe, même histoire amont — donc la première poussée avance sans
    # forcer. Si elle est refusée, c'est que l'histoire amont a bougé entre-temps : la sortie est le
    # `workflow_dispatch` avec `force`, qui archive la tête sous `refs/heads/archive/` avant de la
    # remplacer, et non une suppression du dépôt.
    "src/DurableModule/|durable-magento"
    "src/DurablePhpstan/|durable-phpstan"
    "src/DurableRector/|durable-rector"
)

# Push using Authorization: Basic so the credential helper from CI (GITHUB_TOKEN) cannot override
# pushes to other repositories — embed-only URLs are sometimes ignored when a global helper matches github.com.
satellite_url() {
    printf 'https://github.com/%s/%s.git' "$ORG" "$1"
}

auth_header() {
    local basic
    basic="$(printf 'x-access-token:%s' "$TOKEN" | base64 -w0 2>/dev/null || printf 'x-access-token:%s' "$TOKEN" | base64 | tr -d '\n')"
    printf 'Authorization: Basic %s' "$basic"
}

git_push_satellite() {
    local repo="$1"
    local refspec="$2"
    shift 2
    if [[ -z "$TOKEN" ]]; then
        return 0
    fi
    GIT_TERMINAL_PROMPT=0 git \
        -c "http.extraHeader=$(auth_header)" \
        push "$@" "$(satellite_url "$repo")" "$refspec"
}

# SHA the satellite already carries for <tag>, empty if absent. Tags are pushed lightweight,
# so the single ls-remote line points straight at the split commit.
remote_tag_sha() {
    GIT_TERMINAL_PROMPT=0 git \
        -c "http.extraHeader=$(auth_header)" \
        ls-remote --tags "$(satellite_url "$1")" "refs/tags/$2" | head -n1 | cut -f1
}

# A force-push rewrites refs/heads/main of an already published repository. Park the head it is
# about to drop under refs/heads/archive/ first: the operation stays undoable, and the only copy of
# a satellite commit the current prefix no longer reproduces is not lost.
#
# The head must be fetched before it can be pushed anywhere: `git push <url> <sha>:<ref>` needs the
# object *locally*, and the heads worth archiving are exactly the ones the current prefix does not
# reproduce — so the runner does not have them. Fetching the ref rather than the SHA also closes the
# window between reading the head and archiving it.
#
# Returns non-zero when the head exists but could not be archived: the caller must then leave that
# satellite alone rather than force it. A backup that silently does not happen is worse than none,
# because the force proceeds as if it had.
archive_remote_head() {
    local repo="$1" url head
    url="$(satellite_url "$repo")"

    if [[ -z "$(GIT_TERMINAL_PROMPT=0 git -c "http.extraHeader=$(auth_header)" \
        ls-remote "$url" "refs/heads/$BRANCH" | head -n1 | cut -f1)" ]]; then
        echo "[branch] $ORG/$repo has no refs/heads/$BRANCH yet, nothing to archive"
        return 0
    fi

    if ! GIT_TERMINAL_PROMPT=0 git -c "http.extraHeader=$(auth_header)" \
        fetch --quiet --no-tags "$url" "refs/heads/$BRANCH"; then
        echo "::error::could not fetch $ORG/$repo refs/heads/$BRANCH to archive it" >&2
        return 1
    fi
    head="$(git rev-parse FETCH_HEAD)"

    echo "[branch] Archiving $ORG/$repo $head -> refs/heads/archive/pre-force-${head:0:12}"
    if ! git_push_satellite "$repo" "$head:refs/heads/archive/pre-force-${head:0:12}"; then
        echo "::error::could not archive $ORG/$repo $head" >&2
        return 1
    fi
}

report_failures() {
    if [[ $# -eq 0 ]]; then
        return 0
    fi
    echo "Satellites rejected: $*" >&2
    return 1
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
    local sha force_flag=() failed=()
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
        if [[ ${#force_flag[@]} -gt 0 ]] && ! archive_remote_head "$repo"; then
            echo "::error::[branch] refusing to force $ORG/$repo without an archive of its current head" >&2
            failed+=("$repo")
            continue
        fi
        echo "[branch] Pushing $ORG/$repo $sha -> refs/heads/$BRANCH"
        # Same reason as the "already at" skip in tag mode: the satellites are independent, so one
        # rejected push must not cancel the others. Before this, `durable` is entry 1 and a single
        # non-fast-forward there left the five following repositories unpublished for a whole day.
        if ! git_push_satellite "$repo" "$sha:refs/heads/$BRANCH" "${force_flag[@]}"; then
            echo "::error::[branch] $ORG/$repo rejected $sha -> refs/heads/$BRANCH" >&2
            failed+=("$repo")
        fi
    done

    report_failures "${failed[@]+"${failed[@]}"}"
}

push_tag_mode() {
    local tag="$1"
    local sha failed=()

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
        # Already published at this SHA: skip. Without this, a re-run dies on "already exists" at the
        # first satellite and the remaining ones are never pushed — a partial publish stays stuck.
        # A tag pointing elsewhere still fails loudly: that is a real divergence, not a retry.
        if [[ "$(remote_tag_sha "$repo" "$tag")" == "$sha" ]]; then
            echo "[tag] $repo already at $sha for $tag"
            continue
        fi
        echo "[tag] Pushing $ORG/$repo $sha -> refs/tags/$tag"
        if ! git_push_satellite "$repo" "$sha:refs/tags/$tag"; then
            echo "::error::[tag] $ORG/$repo rejected $sha -> refs/tags/$tag" >&2
            failed+=("$repo")
        fi
    done

    report_failures "${failed[@]+"${failed[@]}"}"
}

if [[ "${1:-}" == "tag" ]]; then
    push_tag_mode "${2:-}"
else
    push_branch_mode
fi
