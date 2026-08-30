#!/usr/bin/env bash
#
# Démarre la démonstration Nexus à trois applications, et raconte ce qu'elle fait.
#
# Six processus — cinq pour les deux maquettes Symfony, un pour le banc Magento —, et l'ordre n'a
# pas d'importance : un worker qui démarre en retard fait attendre,
# il ne fait pas échouer. C'est le sujet même de la démonstration — la sonde l'a montré en direct,
# un `encaisser` étant resté quatre minutes en `NEXUS_OPERATION_STARTED` pendant que son worker
# était éteint, puis s'étant terminé sans que l'appelant ait rien tenu d'ouvert.
#
#   demo/lancer.sh            # démarre les six workers, laisse la main
#   demo/lancer.sh --arreter  # les arrête
#   demo/lancer.sh --etat     # dit qui tourne
#
# Variables : PHP (défaut php8.3), PHP_MAGENTO (défaut php8.2), TEMPORAL_ADDRESS (127.0.0.1:7233),
# DATABASE_URL pour la boutique.
#
# ⚠ Deux binaires PHP, et c'est mesuré, pas frileux. Sur le poste de référence, 8.3 est la seule
# version qui ait `grpc` **et** satisfasse le `php: >=8.3` de la maquette Sylius ; il lui manque
# `pdo_mysql`, `curl` et `soap`, que Mage-OS exige. 8.2 les a tous, `grpc` compris, et le banc
# Magento est épinglé dessus. Une seule version ne fait pas tourner les trois.
#
set -euo pipefail

RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VAR="$RACINE/demo/var"
PHP="${PHP:-php8.3}"
PHP_MAGENTO="${PHP_MAGENTO:-php8.2}"
ADRESSE="${TEMPORAL_ADDRESS:-127.0.0.1:7233}"
BOUTIQUE_DB="${DATABASE_URL:-pgsql://sylius:sylius@127.0.0.1:55432/sylius_demo?serverVersion=16&charset=utf8}"

DSN_BOUTIQUE_SERT="temporal://$ADRESSE?namespace=demo-boutique&nexus_task_queue=demo-boutique-nexus&tls=0"
DSN_BOUTIQUE_APPELLE="temporal://$ADRESSE?namespace=demo-boutique&tls=0"
DSN_METIER="temporal://$ADRESSE?namespace=demo-metier&nexus_task_queue=demo-metier-nexus&journal_task_queue=durable-journal&tls=0"
# Pas de `nexus_task_queue` : Magento ne sert rien, donc rien à poller. Le DSN entre dans le banc
# par `MAGENTO_DC_…`, la convention de Magento pour surcharger `app/etc/env.php` par
# l'environnement — le banc garde ainsi le DSN de sa propre grappe, dont les API Nexus sont
# désactivées.
DSN_MAGENTO="temporal://$ADRESSE?namespace=demo-magento&tls=0"

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

# $1 nom, $2 répertoire, $3 la commande entière, puis les variables d'environnement en NOM=valeur
#
# La commande est une chaîne et non un transport, parce que les trois maquettes ne tournent pas
# leurs workers de la même façon : deux `messenger:consume`, un `bin/magento durable:worker`. C'est
# la seule différence, et elle tient dans un argument.
lancer() {
    local nom="$1" repertoire="$2" commande="$3"
    shift 3

    if [ -e "$VAR/$nom.pid" ] && kill -0 "$(cat "$VAR/$nom.pid")" 2>/dev/null; then
        echo "  = $nom tourne déjà"
        return
    fi

    (
        cd "$RACINE/$repertoire"
        for affectation in "$@"; do
            export "${affectation?}"
        done
        # Sans guillemets : la chaîne porte le binaire et ses arguments, et c'est voulu.
        exec $commande
    ) > "$VAR/$nom.log" 2>&1 &

    echo $! > "$VAR/$nom.pid"
    echo "  + $nom"
}

# Les deux hôtes Symfony, dont un worker est toujours un `messenger:consume` dans un APP_ENV donné.
# $1 nom, $2 répertoire, $3 APP_ENV, $4 transport, puis les variables d'environnement
demarrer() {
    local nom="$1" repertoire="$2" env="$3" transport="$4"
    shift 4

    lancer "$nom" "$repertoire" "$PHP bin/console messenger:consume $transport --no-interaction" \
        "APP_ENV=$env" "$@"
}

mkdir -p "$VAR"

case "${1:-}" in
    --arreter) arreter; exit 0 ;;
    --etat) etat; exit 0 ;;
    -h|--help) sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    '') ;;
    *) echo "option inconnue : $1" >&2; exit 2 ;;
esac

if ! temporal --address "$ADRESSE" operator nexus endpoint get --name demo-boutique-stock >/dev/null 2>&1; then
    echo "L'endpoint demo-boutique-stock n'existe pas — lancez d'abord bin/demo-nexus." >&2
    echo "Un serveur qui répond « Nexus APIs are disabled » ne suffit pas : voir demo/README.md." >&2
    exit 1
fi

echo "la boutique (sylius/)"
demarrer boutique-sert-stock      sylius  demo           durable_temporal_nexus    "DURABLE_TEMPORAL_DSN=$DSN_BOUTIQUE_SERT"    "DATABASE_URL=$BOUTIQUE_DB"
demarrer boutique-workflows       sylius  demo_appelant  durable_temporal_journal  "DURABLE_TEMPORAL_DSN=$DSN_BOUTIQUE_APPELLE" "DATABASE_URL=$BOUTIQUE_DB"

echo "le métier (symfony/)"
demarrer metier-sert-facturation  symfony dev            durable_temporal_nexus    "DURABLE_DSN=$DSN_METIER"
demarrer metier-workflows         symfony dev            durable_temporal_journal  "DURABLE_DSN=$DSN_METIER"
demarrer metier-activites         symfony dev            durable_temporal_activity "DURABLE_DSN=$DSN_METIER"

echo "le banc Magento (magento/)"
# Un seul worker : le workflow de Magento n'a pas d'activité — tout ce qu'il fait est servi
# ailleurs. Un worker d'activité ne ferait que poller une file vide.
lancer magento-workflows magento "$PHP_MAGENTO bin/magento durable:worker --role=journal" \
    "MAGENTO_DC_DURABLE__TEMPORAL__DSN=$DSN_MAGENTO"

cat <<FIN

Les logs sont dans demo/var/. Trois appels à essayer :

  # le métier demande du stock à la boutique — réponse immédiate
  cd symfony && DURABLE_DSN='$DSN_METIER' \\
    bin/console durable:demo:nexus CMD-1 MUG_BLUE=2

  # la boutique fait facturer par le métier — vérification immédiate, encaissement différé
  cd sylius && APP_ENV=demo_appelant DURABLE_TEMPORAL_DSN='$DSN_BOUTIQUE_APPELLE' \\
    DATABASE_URL='$BOUTIQUE_DB' bin/console durable:demo:facturer FACT-1 1200

  # Magento demande du stock à la boutique **et** se fait facturer par le métier — sans rien servir
  cd magento && MAGENTO_DC_DURABLE__TEMPORAL__DSN='$DSN_MAGENTO' \\
    $PHP_MAGENTO bin/magento durable:demo:nexus MAG-1 1200 MUG_BLUE=1

  demo/lancer.sh --etat      # qui tourne
  demo/lancer.sh --arreter   # tout éteindre
FIN
