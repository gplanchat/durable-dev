#!/usr/bin/env bash
# Q1, dependencies: does a Mage-OS line accept what option A adds? One throwaway project per line,
# built like CI's magento-matrix, resolved with --dry-run.
# usage: resolve.sh <mageos-version> <php-platform> <variant: none|bridge|deps>
#   none   the module alone (control)
#   bridge the module plus gplanchat/durable-bridge-dbal by path (what a user would type)
#   deps   the module plus what the bridge requires, without the bridge (what A adds to the graph)
set -u
here=$(cd "$(dirname "$0")" && pwd)
src=$(cd "$here" && cd ../.. && pwd)/src
v=$1 php=$2 variant=$3 dir="$here/resolve/$1-$3"
mkdir -p "$dir"
extra='' repos=''
case $variant in
  bridge) repos=', { "type": "path", "url": "'$src'/Bridge/Dbal", "options": { "symlink": false } }'
          extra=', "gplanchat/durable-bridge-dbal": "@dev"' ;;
  deps)   extra=', "doctrine/dbal": "^3.8.2 || ^4.0", "symfony/lock": "^6.4 || ^7.0 || ^8.0", "symfony/messenger": "^6.4 || ^7.0 || ^8.0"' ;;
esac
cat > "$dir/composer.json" <<JSON
{
  "minimum-stability": "dev", "prefer-stable": true,
  "repositories": [
    { "type": "composer", "url": "https://repo.mage-os.org/" },
    { "type": "path", "url": "$src/DurableModule", "options": { "symlink": false } },
    { "type": "path", "url": "$src/Durable", "options": { "symlink": false } }$repos
  ],
  "require": { "mage-os/product-community-edition": "$v", "gplanchat/durable-magento": "@dev"$extra },
  "config": { "platform": { "php": "$php" }, "allow-plugins": {
    "mage-os/composer-dependency-version-audit-plugin": false, "mage-os/magento-composer-installer": true,
    "mage-os/inventory-composer-installer": true, "laminas/laminas-dependency-plugin": true } }
}
JSON
cd "$dir" && COMPOSER_MEMORY_LIMIT=2G composer update --dry-run --no-interaction --no-audit --no-progress 2>&1
