#!/usr/bin/env bash
#
# Contrôle du contrôle. `prises-check.sh` a trois verdicts et interroge un service distant : ses
# erreurs ne se voient donc qu'en production du registre, et la première a coûté un faux positif
# sur une prise vivante. Ce fichier les fait voir ici.
#
# `gh` est remplacé par un script qui répond depuis un scénario, ce qui rend les cas reproductibles
# et le test hors-ligne.
set -uo pipefail

RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echecs=0

# $1 nom du cas · $2 états des PR (vide, "open", "closed"…) · $3 ahead_by ("" = branche absente)
# $4 sortie attendue : "vivante" ou "perimee"
# $5 préfixe de branche (défaut « chore ») · $6 état du chantier OpenSpec : "" | "reste" | "fini"
cas() {
    local nom="$1" etats="$2" avance="$3" attendu="$4" prefixe="${5:-chore}" chantier="${6:-}"
    local bac="$TMP/$nom"
    mkdir -p "$bac/.worktrees/prises/$prefixe" "$bac/bin"

    printf '# %s/%s\n' "$prefixe" "$nom" > "$bac/.worktrees/prises/$prefixe/$nom.md"

    if [ -n "$chantier" ]; then
        mkdir -p "$bac/openspec/changes/$nom"
        if [ "$chantier" = "reste" ]; then
            printf -- '- [x] 1.1 faite\n- [ ] 1.2 pas encore\n' > "$bac/openspec/changes/$nom/tasks.md"
        else
            printf -- '- [x] 1.1 faite\n- [x] 1.2 faite\n' > "$bac/openspec/changes/$nom/tasks.md"
        fi
    fi
    cp "$RACINE/bin/prises-check.sh" "$bac/bin/"

    # Le faux `gh` : il ne connaît que les deux appels que le script fait.
    cat > "$bac/gh" <<FAUXGH
#!/usr/bin/env bash
for arg in "\$@"; do
    case "\$arg" in
        *pulls?head=*)
            for e in $etats; do echo "\$e"; done
            exit 0 ;;
        */compare/*)
            [ -z "$avance" ] && exit 1
            echo "$avance"
            exit 0 ;;
    esac
done
exit 0
FAUXGH
    chmod +x "$bac/gh"

    local sortie
    sortie="$(cd "$bac" && PATH="$bac:$PATH" bash bin/prises-check.sh dummy/repo 2>&1)"
    # La ligne de résumé contient toujours le mot « périmée(s) » : c'est la ligne d'erreur
    # qu'il faut lire, pas le compte. Premier faux positif de ce fichier, corrigé ici.
    local obtenu="vivante"
    grep -q '::error file=' <<<"$sortie" && obtenu="perimee"

    if [ "$obtenu" = "$attendu" ]; then
        printf '  ok        %-34s %s\n' "$nom" "$attendu"
    else
        printf '  ÉCHEC     %-34s attendu %s, obtenu %s\n' "$nom" "$attendu" "$obtenu"
        sed 's/^/            /' <<<"$sortie"
        echecs=$((echecs + 1))
    fi
}

# Le cas qui a motivé ce fichier : PR fusionnée, branche rouverte pour la tranche suivante.
# Avant le correctif, ce cas était déclaré périmé et la prise vivante partait avec.
cas branche-reutilisee        "closed"      3   vivante

cas aucune-pr                 ""            0   vivante
cas pr-ouverte                "open closed" 0   vivante
cas pr-fermee-rien-a-fusionner "closed"     0   perimee
cas pr-fermee-branche-absente "closed"      ""  perimee
cas plusieurs-fermees         "closed closed" 0 perimee

# Le faux positif structurel, signalé par la session Laravel. Entre la fusion d'une tranche et le
# premier commit de la suivante, une branche de chantier réutilisée réunit les trois conditions —
# PR fermées, rien devant `main`, parfois branche supprimée — alors que le travail continue. Le
# chantier, lui, sait qu'il n'est pas fini.
cas chantier-en-cours         "closed"      0   vivante  change  reste
cas chantier-en-cours-sans-branche "closed" ""  vivante  change  reste

# Et l'inverse doit rester vrai, sans quoi le correctif rendrait le contrôle inutile : un chantier
# dont toutes les tâches sont cochées n'a plus de prise à tenir.
cas chantier-fini             "closed"      0   perimee  change  fini

echo
if [ "$echecs" -eq 0 ]; then
    echo "prises-check : 9 cas, tous conformes"
else
    echo "prises-check : $echecs cas en échec"
fi
[ "$echecs" -eq 0 ]
