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

# One skeleton, copied per guide: the install is the slow part and does not depend on the guide.
SKELETON="$TMP/skeleton"
composer create-project --no-interaction --quiet symfony/skeleton "$SKELETON"
(
    cd "$SKELETON"
    composer config minimum-stability dev
    composer config prefer-stable true
    # The bundle's recipe lives in recipes-contrib, which Flex asks about before running; this is
    # the reader who answers yes. Without it, bundles.php never names DurableBundle.
    composer config extra.symfony.allow-contrib true
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
