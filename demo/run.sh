#!/usr/bin/env bash
#
# Starts the four-application Nexus demonstration, and says what it is doing.
#
# Eight processes — five for the two Symfony mockups, one for the Magento bench, two for the Laravel
# mockup — and the order does not matter: a worker that starts late makes things wait, it does not
# make them fail. That is the very subject of the demonstration — the probe showed it live, a
# `charge` having sat four minutes in `NEXUS_OPERATION_STARTED` while its worker was off, then
# finishing without the caller having held anything open.
#
#   demo/run.sh           # starts the eight workers, hands the terminal back
#   demo/run.sh --stop    # stops them
#   demo/run.sh --status  # says who is running
#
# Variables: PHP (default php8.3), PHP_MAGENTO and PHP_LARAVEL (default php8.2), TEMPORAL_ADDRESS
# (127.0.0.1:7233), DATABASE_URL for the shop.
#
# ⚠ Two PHP binaries, and that is measured, not timid. On the reference machine, 8.3 is the only
# version that has `grpc` **and** satisfies the `php: >=8.3` of the Sylius mockup; it lacks
# `pdo_mysql`, `curl`, `soap` and `pdo_sqlite`, which Mage-OS and Laravel require. 8.2 has them all,
# `grpc` included, and the Magento and Laravel benches are pinned to it. Two variables rather than
# one, although they share a default: the two benches have no reason to stay on the same version
# forever.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VAR="$ROOT/demo/var"
PHP="${PHP:-php8.3}"
PHP_MAGENTO="${PHP_MAGENTO:-php8.2}"
PHP_LARAVEL="${PHP_LARAVEL:-php8.2}"
ADDRESS="${TEMPORAL_ADDRESS:-127.0.0.1:7233}"
SHOP_DB="${DATABASE_URL:-pgsql://sylius:sylius@127.0.0.1:55432/sylius_demo?serverVersion=16&charset=utf8}"

DSN_SHOP_SERVES="temporal://$ADDRESS?namespace=demo-shop&nexus_task_queue=demo-shop-nexus&tls=0"
DSN_SHOP_CALLS="temporal://$ADDRESS?namespace=demo-shop&tls=0"
DSN_BUSINESS="temporal://$ADDRESS?namespace=demo-business&nexus_task_queue=demo-business-nexus&journal_task_queue=durable-journal&tls=0"
# No `nexus_task_queue`: Magento serves nothing, so there is nothing to poll. The DSN enters the
# bench through `MAGENTO_DC_…`, Magento's convention for overriding `app/etc/env.php` from the
# environment — the bench thus keeps the DSN of its own cluster, whose Nexus APIs are disabled.
DSN_MAGENTO="temporal://$ADDRESS?namespace=demo-magento&tls=0"
# The only one of the four to carry `nexus_task_queue` without being a Symfony mockup: the logistics
# serves `delivery`, and its two workers read the same DSN — one polls the Nexus queue, the other
# the workflow task queue.
DSN_LARAVEL="temporal://$ADDRESS?namespace=demo-laravel&nexus_task_queue=demo-laravel-nexus&tls=0"

status() {
    local alive=0
    for pidf in "$VAR"/*.pid; do
        [ -e "$pidf" ] || continue
        local pid name
        pid="$(cat "$pidf")"
        name="$(basename "$pidf" .pid)"
        if kill -0 "$pid" 2>/dev/null; then
            echo "  ● $name (pid $pid)"
            alive=$((alive + 1))
        else
            echo "  ○ $name — off"
            rm -f "$pidf"
        fi
    done
    [ "$alive" = 0 ] && echo "  (no worker)"
    return 0
}

stop() {
    for pidf in "$VAR"/*.pid; do
        [ -e "$pidf" ] || continue
        local pid
        pid="$(cat "$pidf")"
        kill "$pid" 2>/dev/null && echo "  stopped $(basename "$pidf" .pid)"
        rm -f "$pidf"
    done
}

# $1 name, $2 directory, $3 the whole command, then the environment variables as NAME=value
#
# The command is a string and not a transport, because the three mockups do not run their workers
# the same way: two `messenger:consume`, one `bin/magento durable:worker`. That is the only
# difference, and it fits in one argument.
spawn() {
    local name="$1" directory="$2" command="$3"
    shift 3

    if [ -e "$VAR/$name.pid" ] && kill -0 "$(cat "$VAR/$name.pid")" 2>/dev/null; then
        echo "  = $name is already running"
        return
    fi

    (
        cd "$ROOT/$directory"
        for assignment in "$@"; do
            export "${assignment?}"
        done
        # Unquoted: the string carries the binary and its arguments, and that is deliberate.
        exec $command
    ) > "$VAR/$name.log" 2>&1 &

    echo $! > "$VAR/$name.pid"
    echo "  + $name"
}

# The two Symfony hosts, whose workers are always a `messenger:consume` in a given APP_ENV.
# $1 name, $2 directory, $3 APP_ENV, $4 transport, then the environment variables
consume() {
    local name="$1" directory="$2" env="$3" transport="$4"
    shift 4

    spawn "$name" "$directory" "$PHP bin/console messenger:consume $transport --no-interaction" \
        "APP_ENV=$env" "$@"
}

mkdir -p "$VAR"

case "${1:-}" in
    --stop) stop; exit 0 ;;
    --status) status; exit 0 ;;
    -h|--help) sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    '') ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
esac

if ! temporal --address "$ADDRESS" operator nexus endpoint get --name demo-shop-stock >/dev/null 2>&1; then
    echo "The endpoint demo-shop-stock does not exist — run bin/demo-nexus first." >&2
    echo "A server answering \"Nexus APIs are disabled\" is not enough: see demo/README.md." >&2
    exit 1
fi

echo "the shop (sylius/)"
consume shop-serves-stock     sylius  demo         durable_temporal_nexus    "DURABLE_TEMPORAL_DSN=$DSN_SHOP_SERVES" "DATABASE_URL=$SHOP_DB"
consume shop-workflows        sylius  demo_caller  durable_temporal_journal  "DURABLE_TEMPORAL_DSN=$DSN_SHOP_CALLS"  "DATABASE_URL=$SHOP_DB"

echo "the business (symfony/)"
consume business-serves-billing symfony dev        durable_temporal_nexus    "DURABLE_DSN=$DSN_BUSINESS"
consume business-workflows      symfony dev        durable_temporal_journal  "DURABLE_DSN=$DSN_BUSINESS"
consume business-activities     symfony dev        durable_temporal_activity "DURABLE_DSN=$DSN_BUSINESS"

echo "the Magento bench (magento/)"
# One worker only: Magento's workflow has no activity — everything it does is served elsewhere. An
# activity worker would do nothing but poll an empty queue.
spawn magento-workflows magento "$PHP_MAGENTO bin/magento durable:worker --role=journal" \
    "MAGENTO_DC_DURABLE__TEMPORAL__DSN=$DSN_MAGENTO"

echo "the logistics (laravel/)"
# Two workers, and not three: `ShipWorkflow` has no activity — what it waits for, it waits for from
# a timer and from a Nexus operation served elsewhere.
spawn logistics-serves-delivery laravel "$PHP_LARAVEL artisan durable:nexus-worker" \
    "DURABLE_DSN=$DSN_LARAVEL"
spawn logistics-workflows       laravel "$PHP_LARAVEL artisan durable:temporal-worker" \
    "DURABLE_DSN=$DSN_LARAVEL"

cat <<END

The logs are in demo/var/. Three calls to try — the third crosses all four mockups:

  # the business asks the shop for stock — an immediate answer
  cd symfony && DURABLE_DSN='$DSN_BUSINESS' \\
    bin/console durable:demo:nexus CMD-1 MUG_BLUE=2

  # the shop has the business bill an order — immediate verification, deferred charge
  cd sylius && APP_ENV=demo_caller DURABLE_TEMPORAL_DSN='$DSN_SHOP_CALLS' \\
    DATABASE_URL='$SHOP_DB' bin/console durable:demo:bill BILL-1 1200

  # Magento asks the shop for stock **and** is billed by the business — while serving nothing
  cd magento && MAGENTO_DC_DURABLE__TEMPORAL__DSN='$DSN_MAGENTO' \\
    $PHP_MAGENTO bin/magento durable:demo:nexus MAG-1 1200 MUG_BLUE=1

  demo/run.sh --status   # who is running
  demo/run.sh --stop     # shut everything down
END
