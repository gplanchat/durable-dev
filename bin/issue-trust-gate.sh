#!/usr/bin/env bash
# Labels issues by their author's association with the repository, so the agentic loop can tell a
# teammate's issue from a stranger's. See .github/workflows/issue-trust-gate.yml and WA007.
#
#   issue-trust-gate.sh --classify <ASSOCIATION>            print the label, touch nothing
#   issue-trust-gate.sh --apply <number> <assoc> [action]   label one issue
#   issue-trust-gate.sh --backfill                          label every open issue
#   issue-trust-gate.sh --ensure-labels                     create the labels if missing
#
# --classify is pure: no network, no token, no side effect. That is what bin/issue-trust-gate-test.sh
# exercises, because the classification is the security decision and the rest is plumbing.
set -euo pipefail

REPO="${REPO:-$(gh repo view --json nameWithOwner -q .nameWithOwner 2>/dev/null || echo "")}"

# CONTRIBUTOR means one merged pull request, not team membership, so it stays outside. Anything
# unrecognised — a new association GitHub adds later, an empty value, a malformed payload — is
# untrusted. A trust gate that fails open is not a trust gate.
classify() {
  case "${1:-}" in
    OWNER|MEMBER|COLLABORATOR) echo "loop:trusted" ;;
    *)                         echo "loop:untrusted" ;;
  esac
}

ensure_labels() {
  ensure() { gh label create "$1" --repo "$REPO" --color "$2" --description "$3" 2>/dev/null || true; }
  ensure "loop:trusted"   "0e8a16" "Author is on the team; the agentic loop may read this issue"
  ensure "loop:untrusted" "b60205" "Author is outside the team; the loop needs a human sign-off"
  ensure "loop:cleared"   "1d76db" "A human has read this issue and cleared it for the loop"
}

apply() {
  local number=$1 assoc=$2 action=${3:-} add drop
  add=$(classify "$assoc")
  [ "$add" = "loop:trusted" ] && drop="loop:untrusted" || drop="loop:trusted"

  # An edit invalidates any clearance. Otherwise the gate is trivially defeated: open something
  # harmless, wait for a human to clear it, then edit the text. Clearance is a statement about
  # content that was read, so changing the content withdraws it. A backfill never withdraws one —
  # it is labelling history, not reacting to a change.
  if [ "$action" = "edited" ]; then
    drop="$drop,loop:cleared"
    echo "::notice::Issue #$number was edited; any prior loop:cleared is withdrawn."
  fi

  echo "#$number $assoc -> $add (removing $drop)"
  gh issue edit "$number" --repo "$REPO" --add-label "$add" 2>/dev/null || true
  local IFS=','
  for d in $drop; do
    gh issue edit "$number" --repo "$REPO" --remove-label "$d" 2>/dev/null || true
  done
}

case "${1:-}" in
  --classify)      classify "${2:-}" ;;
  --ensure-labels) ensure_labels ;;
  --apply)         ensure_labels; apply "$2" "$3" "${4:-}" ;;
  --backfill)
    ensure_labels
    gh api --paginate "repos/$REPO/issues?state=open&per_page=100" \
      -q '.[] | select(.pull_request == null) | "\(.number) \(.author_association)"' \
      | while read -r n a; do apply "$n" "$a"; done ;;
  *) echo "usage: $0 --classify|--apply|--backfill|--ensure-labels" >&2; exit 2 ;;
esac
