#!/usr/bin/env bash
#
# Démarre la démonstration Nexus à deux applications, et raconte ce qu'elle fait.
#
# Six processus, et l'ordre n'a pas d'importance : un worker qui démarre en retard fait attendre,
# il ne fait pas échouer. C'est le sujet même de la démonstration — la sonde l'a montré en direct,
# un `encaisser` étant resté quatre minutes en `NEXUS_OPERATION_STARTED` pendant que son worker
# était éteint, puis s'étant terminé sans que l'appelant ait rien tenu d'ouvert.
#
#   demo/lancer.sh            # démarre les six workers, laisse la main
#   demo/lancer.sh --arreter  # les arrête
#   demo/lancer.sh --etat     # dit qui tourne
#
# Variables : PHP (défaut php8.3), TEMPORAL_ADDRESS (127.0.0.1:7233), DATABASE_URL pour la boutique.
#
set -euo pipefail

RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VAR="$RACINE/demo/var"
PHP="${PHP:-php8.3}"
ADRESSE="${TEMPORAL_ADDRESS:-127.0.0.1:7233}"
BOUTIQUE_DB="${DATABASE_URL:-pgsql://sylius:sylius@127.0.0.1:55432/sylius_demo?serverVersion=16&charset=utf8}"

DSN_BOUTIQUE_SERT="temporal://$ADRESSE?namespace=demo-boutique&nexus_task_queue=demo-boutique-nexus&tls=0"
DSN_BOUTIQUE_APPELLE="temporal://$ADRESSE?namespace=demo-boutique&tls=0"
DSN_METIER="temporal://$ADRESSE?namespace=demo-metier&nexus_task_queue=demo-metier-nexus&journal_task_queue=durable-journal&tls=0"

etat() {
    local vivants=0
    for pidf in "$VAR"/*.pid; do
        [ -e "$pidf" ] || continue
        local pid nom
        pid="$(cat "$pidf")"
        nom="$(basename "$pidf" .pid)"
        if kill -0 "$pid" 2>/dev/null; then
            echo "  ● $nom (pid $pid)"
            vivants=$((vivants + 1))
        else
            echo "  ○ $nom — éteint"
            rm -f "$pidf"
        fi
    done
    [ "$vivants" = 0 ] && echo "  (aucun worker)"
    return 0
}

arreter() {
    for pidf in "$VAR"/*.pid; do
        [ -e "$pidf" ] || continue
        local pid
        pid="$(cat "$pidf")"
        kill "$pid" 2>/dev/null && echo "  arrêté $(basename "$pidf" .pid)"
        rm -f "$pidf"
    done
}

# $1 nom, $2 répertoire, $3 APP_ENV, $4 transport, puis les variables d'environnement en NOM=valeur
demarrer() {
    local nom="$1" repertoire="$2" env="$3" transport="$4"
    shift 4

    if [ -e "$VAR/$nom.pid" ] && kill -0 "$(cat "$VAR/$nom.pid")" 2>/dev/null; then
        echo "  = $nom tourne déjà"
        return
    fi

    (
        cd "$RACINE/$repertoire"
        export APP_ENV="$env"
        for affectation in "$@"; do
            export "${affectation?}"
        done
        exec "$PHP" bin/console messenger:consume "$transport" --no-interaction
    ) > "$VAR/$nom.log" 2>&1 &

    echo $! > "$VAR/$nom.pid"
    echo "  + $nom"
}

mkdir -p "$VAR"

case "${1:-}" in
    --arreter) arreter; exit 0 ;;
    --etat) etat; exit 0 ;;
    -h|--help) sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    '') ;;
    *) echo "option inconnue : $1" >&2; exit 2 ;;
esac

if ! temporal --address "$ADRESSE" operator nexus endpoint get --name demo-boutique-stock >/dev/null 2>&1; then
    echo "L'endpoint demo-boutique-stock n'existe pas — lancez d'abord bin/demo-nexus." >&2
    echo "Un serveur qui répond « Nexus APIs are disabled » ne suffit pas : voir demo/README.md." >&2
    exit 1
fi

echo "la boutique (sylius/)"
demarrer boutique-sert-stock      sylius  dev  durable_temporal_nexus    "DURABLE_TEMPORAL_DSN=$DSN_BOUTIQUE_SERT"    "DATABASE_URL=$BOUTIQUE_DB"
demarrer boutique-workflows       sylius  demo durable_temporal_journal  "DURABLE_TEMPORAL_DSN=$DSN_BOUTIQUE_APPELLE" "DATABASE_URL=$BOUTIQUE_DB"

echo "le métier (symfony/)"
demarrer metier-sert-facturation  symfony dev  durable_temporal_nexus    "DURABLE_DSN=$DSN_METIER"
demarrer metier-workflows         symfony dev  durable_temporal_journal  "DURABLE_DSN=$DSN_METIER"
demarrer metier-activites         symfony dev  durable_temporal_activity "DURABLE_DSN=$DSN_METIER"

cat <<FIN

Les logs sont dans demo/var/. Deux appels à essayer, un dans chaque sens :

  # le métier demande du stock à la boutique — réponse immédiate
  cd symfony && DURABLE_DSN='$DSN_METIER' \\
    bin/console durable:demo:nexus CMD-1 MUG_BLUE=2

  # la boutique fait facturer par le métier — vérification immédiate, encaissement différé
  cd sylius && APP_ENV=demo DURABLE_TEMPORAL_DSN='$DSN_BOUTIQUE_APPELLE' \\
    DATABASE_URL='$BOUTIQUE_DB' bin/console durable:demo:facturer FACT-1 1200

  demo/lancer.sh --etat      # qui tourne
  demo/lancer.sh --arreter   # tout éteindre
FIN
