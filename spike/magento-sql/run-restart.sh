#!/usr/bin/env bash
# The #709 key experiment: activity + timer + signal on Mage-OS, journal on its own MySQL server,
# with a worker killed (-9) twice: once mid-activity, once while the timer is pending. The signal is
# sent while no worker runs. Needs the spike709-journal container and an installed mageos/.
set -u
here=$(cd "$(dirname "$0")" && pwd); cd "$here/mageos"
id=${1:-order-restart}; out="$here/out/restart"; mkdir -p "$out"; rm -f "$out"/* var/log/spike-activities.log
W="php -d memory_limit=2G bin/magento durable-sql:spike"  # unquoted on purpose: $! must be php itself
spike() { $W "$@"; }
sql() { docker exec spike709-journal mysql -udurable -pdurable durable -N -e "$1" 2>/dev/null; }
has() { [ "$(sql "SELECT COUNT(*) FROM durable_events WHERE execution_id='$id' AND event_type LIKE '%$1'")" -ge "${2:-1}" ]; }
wait_for() { for _ in $(seq 1 300); do has "$@" && return 0; sleep 0.2; done; echo "timeout waiting for $1" >&2; return 1; }
ts() { date -u +%H:%M:%S.%N | cut -c1-12; }

sql "TRUNCATE durable_events; TRUNCATE durable_queue; TRUNCATE durable_workflow_metadata; TRUNCATE durable_workflow_runs; TRUNCATE durable_execution_heads; TRUNCATE lock_keys;"
spike start "$id" 6 8                                  # charge sleeps 6 s, then an 8 s timer

$W work 120 > "$out/worker1.txt" 2>&1 & w=$!
wait_for ActivityTaskStarted; sleep 2                  # inside charge's 6 s pause
kill -9 $w; echo "$(ts) killed worker 1 (pid $w) mid-activity" | tee "$out/timeline.txt"

$W work 120 > "$out/worker2.txt" 2>&1 & w=$!
wait_for TimerScheduled; sleep 2                       # the timer is pending
kill -9 $w; echo "$(ts) killed worker 2 (pid $w) with the timer pending" | tee -a "$out/timeline.txt"

spike signal "$id" alice | tee -a "$out/timeline.txt"  # no worker is running
sleep 8                                                # the timer comes due while nobody works

$W work 25 > "$out/worker3.txt" 2>&1 & w=$!
wait_for ExecutionCompleted && echo "$(ts) completed" | tee -a "$out/timeline.txt"
kill $w 2>/dev/null; wait 2>/dev/null
spike status "$id" | tee "$out/status.txt"
cp var/log/spike-activities.log "$out/side-effects.txt"
