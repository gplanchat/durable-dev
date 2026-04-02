#!/usr/bin/env bash
# Publie les sous-arborescences du monorepo vers les dépôts distants (splitsh-lite).
# Prérequis : https://github.com/splitsh/lite installé et dans le PATH sous le nom splitsh-lite.
# CI : .github/workflows/splitsh.yml (build v2.0.0 + libgit2, checkout fetch-depth: 0).
#
# Push automatique (optionnel) :
#   SPLITSH_PUSH_TOKEN   — PAT GitHub avec contents:write sur chaque dépôt satellite (HTTPS).
#   SPLITSH_TARGET_BRANCH — branche cible (défaut : main → refs/heads/main).
#   SPLITSH_PUSH_FORCE=1 — git push --force (danger).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Sur GitHub Actions, actions/checkout ajoute http.https://github.com/.extraheader (Bearer GITHUB_TOKEN).
# Ce jeton ne vaut que pour le dépôt courant : il est préféré au PAT dans l’URL et le push vers les
# miroirs échoue en 403 en tant que github-actions[bot]. Le retirer avant les push HTTPS avec SPLITSH_PUSH_TOKEN.
if [ -n "${SPLITSH_PUSH_TOKEN:-}" ] && [ -n "${GITHUB_ACTIONS:-}" ]; then
  git config --local --unset-all http.https://github.com/.extraheader 2>/dev/null || true
  git config --global --unset-all http.https://github.com/.extraheader 2>/dev/null || true
fi

if ! command -v splitsh-lite >/dev/null 2>&1; then
  echo "splitsh-lite introuvable. Installez-le : https://github.com/splitsh/lite" >&2
  exit 1
fi

remote_for_prefix() {
  case "$1" in
    src/Durable) echo "git@github.com:gplanchat/durable.git" ;;
    src/DurableBundle) echo "git@github.com:gplanchat/durable-bundle.git" ;;
    src/Bridge/Temporal) echo "git@github.com:gplanchat/durable-bridge-temporal.git" ;;
    src/DurablePhpStan) echo "git@github.com:gplanchat/durable-phpstan.git" ;;
    src/DurablePsalmPlugin) echo "git@github.com:gplanchat/durable-psalm-plugin.git" ;;
    *) echo "" ;;
  esac
}

target_ref() {
  local b="${SPLITSH_TARGET_BRANCH:-main}"
  if [ -z "$b" ]; then
    b=main
  fi
  if [[ "$b" == refs/* ]]; then
    echo "$b"
  else
    echo "refs/heads/${b}"
  fi
}

push_split_to_github() {
  local remote_ssh="$1"
  local sha="$2"
  local ref
  ref="$(target_ref)"
  # git@github.com:org/repo.git → https://github.com/org/repo.git
  local path="${remote_ssh#git@github.com:}"
  local url="https://x-access-token:${SPLITSH_PUSH_TOKEN}@github.com/${path}"
  if [ -n "${SPLITSH_PUSH_FORCE:-}" ]; then
    git push --force "$url" "${sha}:${ref}"
  else
    git push "$url" "${sha}:${ref}"
  fi
}

for prefix in src/Durable src/DurableBundle src/Bridge/Temporal src/DurablePhpStan src/DurablePsalmPlugin; do
  if [ ! -d "$prefix" ]; then
    echo "!! splitsh : dossier absent, ignoré — $prefix (créez l’arborescence ou retirez ce préfixe du script)" >&2
    continue
  fi
  remote="$(remote_for_prefix "$prefix")"
  echo "==> splitsh-lite --prefix=$prefix"
  sha="$(splitsh-lite --prefix="$prefix")"
  echo "    commit $sha -> ${remote}"
  if [ -n "${SPLITSH_PUSH_TOKEN:-}" ]; then
    echo "    (push HTTPS) -> ${remote} $(target_ref)"
    push_split_to_github "$remote" "$sha"
  else
    echo "    (exemple) git push ${remote} ${sha}:$(target_ref)"
  fi
done

echo
if [ -n "${SPLITSH_PUSH_TOKEN:-}" ]; then
  echo "Résumé : push HTTPS effectué vers chaque dépôt (secret SPLITSH_PUSH_TOKEN défini)."
else
  echo "Résumé : exécutez manuellement git push pour chaque SHA vers la branche voulue ($(target_ref), etc.)."
  echo "En CI : ajoutez le secret SPLITSH_PUSH_TOKEN (PAT) pour pousser automatiquement."
  echo "Ajoutez SPLITSH_PUSH_FORCE=1 si vous réécrivez l’historique du dépôt cible."
fi
