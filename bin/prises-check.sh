#!/usr/bin/env bash
#
# Contrôle du registre des prises : `.worktrees/prises/<branche>.md`.
#
# Deux fois le 2026-08-27, le registre a menti sans que rien ne rougisse — une prise déjà refermée
# qui traînait dans une branche, puis une prise vivante emportée par un rebasage plus vieux qu'elle.
# Les deux fois, c'est une session qui l'a dit à l'autre. Ce script est ce qui remplace cette
# chance.
#
# **Le critère est la PR, pas la branche.** Un premier jet comparait les prises aux branches
# distantes vivantes : il rougissait sur le cas normal, puisqu'une prise se pose *avant* que la
# branche existe. Un contrôle qui rougit sur le cas normal se fait désarmer dans la semaine, et on
# se retrouve avec moins que rien — un check mort plus la croyance qu'il surveille quelque chose.
#
# Une prise est périmée quand sa branche a **au moins une PR fermée et aucune PR ouverte** :
#
#   | prises | PR de la branche        | verdict                                     |
#   |--------|-------------------------|---------------------------------------------|
#   | oui    | aucune                  | normal — la prise précède la PR              |
#   | oui    | une ouverte             | normal — le travail est en cours             |
#   | oui    | fermées seulement       | **périmée** — le retrait a été oublié        |
#
# Usage : bin/prises-check.sh [dépôt]      (défaut : gplanchat/durable-dev)
set -uo pipefail

REPO="${1:-gplanchat/durable-dev}"
OWNER="${REPO%%/*}"
RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PRISES="$RACINE/.worktrees/prises"

if [ ! -d "$PRISES" ]; then
    echo "::error::$PRISES n'existe pas — le registre a disparu ou le script est mal placé"
    exit 1
fi

perimees=0
malformees=0
vivantes=0

while IFS= read -r fichier; do
    branche="${fichier#"$PRISES"/}"
    branche="${branche%.md}"

    # Le chemin *est* le nom de la branche : un titre qui dit autre chose rend le registre
    # illisible pour qui le lit à l'œil plutôt qu'avec ce script.
    titre="$(head -n1 "$fichier" | sed 's/^# *//')"
    if [ "$titre" != "$branche" ]; then
        echo "::error file=.worktrees/prises/$branche.md::le titre dit « $titre », le chemin dit « $branche »"
        malformees=$((malformees + 1))
        continue
    fi

    reponse="$(gh api "repos/$REPO/pulls?head=$OWNER:$branche&state=all&per_page=100" --jq '.[].state' 2>&1)"
    if [ $? -ne 0 ]; then
        # Un contrôle qui passe quand il n'a pas pu vérifier ne contrôle rien.
        echo "::error::interrogation des PR impossible pour « $branche » : $reponse"
        exit 1
    fi

    if [ -z "$reponse" ]; then
        vivantes=$((vivantes + 1))
        echo "  ok        $branche — aucune PR, la prise précède le travail"
        continue
    fi

    if grep -qx 'open' <<<"$reponse"; then
        vivantes=$((vivantes + 1))
        echo "  ok        $branche — PR ouverte"
        continue
    fi

    numeros="$(gh api "repos/$REPO/pulls?head=$OWNER:$branche&state=all&per_page=100" --jq '[.[] | "#\(.number)"] | join(", ")')"
    echo "::error file=.worktrees/prises/$branche.md::prise périmée — $numeros fermée(s), aucune ouverte. Retirer sa prise fait partie de la fusion."
    perimees=$((perimees + 1))
done < <(find "$PRISES" -name '*.md' | sort)

echo
echo "registre : $vivantes prise(s) en cours, $perimees périmée(s), $malformees malformée(s)"

[ $((perimees + malformees)) -eq 0 ]
