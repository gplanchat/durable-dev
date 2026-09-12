#!/bin/bash
# Les deux workers Temporal dont la démo d'agent a besoin (spike/agent-durable-symfony-ai).
#
#   ./bin/spike-workers.sh          démarre les deux en tâche de fond
#   pgrep -f messenger:consume      vérifie qu'ils tournent
#
# Deux workers séparés, pas un seul : les transports Temporal sont des long-polls gRPC et
# affament tout transport qui partage leur boucle.
#
# Les valeurs d'environnement sont requotées : `symfony var:export` rend DATABASE_URL avec ses
# `&` nus, que `eval` coupe en tâches de fond — l'URL arrivait tronquée à `sslmode=disable`.
set -u
cd "$(dirname "$0")/.." || exit 1

eval "$(symfony var:export --multiline 2>/dev/null | sed "s/^export \([A-Za-z_][A-Za-z0-9_]*\)=\(.*\)$/export \1='\2'/")"

for transport in durable_temporal_journal durable_temporal_activity; do
    if pgrep -f "messenger:consume $transport" > /dev/null; then
        echo "déjà en cours : $transport"
        continue
    fi
    # setsid + nohup : sans détachement, les workers meurent avec le shell qui les a lancés.
    setsid nohup php bin/console messenger:consume "$transport" > "var/log/$transport.log" 2>&1 < /dev/null &
    disown
    echo "démarré : $transport (var/log/$transport.log)"
done
