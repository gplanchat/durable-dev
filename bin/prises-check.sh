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
# **La PR ne suffit pas non plus.** Une branche survit à sa PR : un chantier qui avance par tranches
# rouvre la même branche pour la suivante, et entre les deux elle n'a que des PR fermées. Le
# 2026-08-27, ce script a déclaré périmée `docs/roadmap-integrations-php`, qui portait trois commits
# non fusionnés et un worktree monté. Retirer cette prise aurait libéré une branche en cours
# d'usage — l'accident même que le registre existe pour empêcher.
#
# Le verdict demande donc les deux : plus aucune PR vivante, **et** plus rien à fusionner.
#
#   | PR de la branche  | branche distante          | verdict                                    |
#   |-------------------|---------------------------|--------------------------------------------|
#   | aucune            | —                         | normal — la prise précède la PR             |
#   | une ouverte       | —                         | normal — le travail est en cours            |
#   | fermées seulement | en avance sur `main`      | normal — réutilisée, tranche suivante       |
#   | fermées seulement | absente                   | **périmée** — la branche a été supprimée    |
#   | fermées seulement | rien de plus que `main`   | **périmée** — le retrait a été oublié       |
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

    # Plus aucune PR vivante. Reste la seconde question, celle qui manquait : la branche
    # a-t-elle encore quelque chose à donner ? `ahead_by` compte ce qu'elle porte et que `main`
    # n'a pas. Une branche absente fait 404, et c'est un verdict, pas une panne.
    avance="$(gh api "repos/$REPO/compare/main...$branche" --jq '.ahead_by' 2>/dev/null)"

    if [ -n "$avance" ] && [ "$avance" -gt 0 ] 2>/dev/null; then
        vivantes=$((vivantes + 1))
        echo "  ok        $branche — PR fermée, mais $avance commit(s) non fusionné(s) : branche réutilisée"
        continue
    fi

    numeros="$(gh api "repos/$REPO/pulls?head=$OWNER:$branche&state=all&per_page=100" --jq '[.[] | "#\(.number)"] | join(", ")')"
    if [ -z "$avance" ]; then
        motif="la branche n'existe plus sur le distant"
    else
        motif="$numeros fermée(s), et la branche n'a rien que \`main\` n'ait déjà"
    fi
    echo "::error file=.worktrees/prises/$branche.md::prise périmée — $motif. Retirer sa prise fait partie de la fusion."
    perimees=$((perimees + 1))
done < <(find "$PRISES" -name '*.md' | sort)

echo
echo "registre : $vivantes prise(s) en cours, $perimees périmée(s), $malformees malformée(s)"

[ $((perimees + malformees)) -eq 0 ]
