#!/usr/bin/env bash
#
# The bench that follows the guide (#363, left open by #276). A fresh Symfony skeleton, this
# repository's packages, the getting-started guide's blocks applied verbatim, and the first workflow
# run to completion. A guide edit that no longer builds a container fails here, not on a reader.
#
# Usage: bin/guide-follows.sh [guide.md ...]   (default: the English and French getting-started)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

[ $# -gt 0 ] || set -- "$ROOT"/documentation/user/getting-started/_index{,.fr}.md

# The bundle's recipe lives in recipes-contrib, which Flex skips unless told otherwise; the guide
# tells the reader to allow it before `composer require` (#443). The bench runs that line because the
# guide says it, and a guide that stops saying it, or says it after the require, fails here:
# bundles.php would never name the bundle.
ALLOW_CONTRIB='composer config extra.symfony.allow-contrib true'
for guide in "$@"; do
    awk -v a="$ALLOW_CONTRIB" -v r='composer require gplanchat/durable-bundle' \
        '$0==a && !seen {ok=1} $0==r {seen=1} END {exit !(ok && seen)}' "$guide" \
        || { echo "$guide must tell the reader '$ALLOW_CONTRIB' before 'composer require gplanchat/durable-bundle'" >&2; exit 1; }
done

# One skeleton, copied per guide: the install is the slow part and does not depend on the guide.
SKELETON="$TMP/skeleton"
composer create-project --no-interaction --quiet symfony/skeleton "$SKELETON"
(
    cd "$SKELETON"
    composer config minimum-stability dev
    composer config prefer-stable true
    $ALLOW_CONTRIB
    for package in Durable DurableBundle; do
        composer config "repositories.${package,,}" "{\"type\": \"path\", \"url\": \"$ROOT/src/$package\"}"
    done
    composer require --no-interaction --quiet gplanchat/durable-bundle:@dev gplanchat/durable:@dev
)

for guide in "$@"; do
    echo "== $guide"
    app="$TMP/app"
    rm -rf "$app" && cp -a "$SKELETON" "$app"
    php "$ROOT/bin/guide-follows/extract.php" "$guide" "$app"
    (cd "$app" && composer dump-autoload --quiet && php "$ROOT/bin/guide-follows/run.php")
done
