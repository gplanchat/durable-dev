#!/usr/bin/env bash
#
# Checking the check. `issue-trust-gate.sh` decides which issues the agentic loop is allowed to
# read on its own, so its classification is a security boundary and not a convenience. A boundary
# that is only ever exercised in production is a boundary nobody has tested.
#
# `--classify` is pure, so most of this runs with no network and no token. The one case that has a
# side effect — an edit withdrawing a human's clearance — is exercised against a stubbed `gh`.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GATE="$ROOT/bin/issue-trust-gate.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0

# $1 association · $2 expected label
classifies() {
  local got
  got=$("$GATE" --classify "$1")
  if [ "$got" = "$2" ]; then
    printf '  ok    %-24s -> %s\n' "${1:-(empty)}" "$got"
  else
    printf '  FAIL  %-24s -> %s (expected %s)\n' "${1:-(empty)}" "$got" "$2"
    failures=$((failures + 1))
  fi
}

echo "classification"
# On the team.
classifies OWNER                  loop:trusted
classifies MEMBER                 loop:trusted
classifies COLLABORATOR           loop:trusted

# Outside it. CONTRIBUTOR is the one that reads like membership and is not: it means a merged pull
# request, which anybody can earn once.
classifies CONTRIBUTOR            loop:untrusted
classifies FIRST_TIME_CONTRIBUTOR loop:untrusted
classifies FIRST_TIMER            loop:untrusted
classifies MANNEQUIN              loop:untrusted
classifies NONE                   loop:untrusted

# The gate must fail closed. An association GitHub invents later, an empty value from a malformed
# payload, or a lowercase spelling must never come out trusted.
classifies ""                     loop:untrusted
classifies owner                  loop:untrusted
classifies SOME_FUTURE_ROLE       loop:untrusted
classifies "OWNER MEMBER"         loop:untrusted

# --- the edit case, against a stubbed gh -------------------------------------------------------
echo "an edit withdraws a human's clearance"
cat > "$TMP/gh" <<'STUB'
#!/usr/bin/env bash
echo "$@" >> "$GH_CALLS"
exit 0
STUB
chmod +x "$TMP/gh"
export GH_CALLS="$TMP/calls.txt"
: > "$GH_CALLS"

PATH="$TMP:$PATH" REPO="owner/repo" "$GATE" --apply 42 NONE edited >/dev/null 2>&1

if grep -q -- "--remove-label loop:cleared" "$GH_CALLS"; then
  echo "  ok    edited -> loop:cleared removed"
else
  echo "  FAIL  edited -> loop:cleared was NOT removed"
  failures=$((failures + 1))
fi

: > "$GH_CALLS"
PATH="$TMP:$PATH" REPO="owner/repo" "$GATE" --apply 42 NONE opened >/dev/null 2>&1
if grep -q -- "--remove-label loop:cleared" "$GH_CALLS"; then
  echo "  FAIL  opened -> loop:cleared removed, but only an edit should withdraw it"
  failures=$((failures + 1))
else
  echo "  ok    opened -> clearance left alone"
fi

echo
if [ "$failures" -eq 0 ]; then echo "all good"; else echo "$failures failure(s)"; fi
exit $((failures > 0))
