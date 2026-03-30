# Site de documentation (Hugo)

Documentation publique générée avec [Hugo](https://gohugo.io/) et le thème [Hugo Book](https://github.com/alex-shpak/hugo-book) (sous-module Git : `themes/hugo-book`).

## Prérequis

- [Hugo Extended](https://gohugo.io/installation/) (pour Sass/SCSS du thème)
- Après un clone du dépôt : `git submodule update --init --recursive`

## Parcours pédagogique

La page **`content/docs/parcours-premier-workflow.md`** raconte un parcours **installation → schéma → premier workflow** (monorepo ou projet Composer). Elle est mise en avant depuis l’accueil et l’introduction `docs/`.

## Développement local

```bash
cd hugo-docs
hugo server --buildDrafts
```

Ouvrir l’URL affichée (souvent http://localhost:1313/durable-dev/).

## Contenu

- Pages : `content/docs/` (introduction avec **cartes** Hugo Book, guides, **architecture** ADR/WA/OST/PRD, **contribuer**).
- Accueil : bandeau visuel (`content/_index.md`) + styles `assets/_custom.scss`, police chargée via `layouts/partials/docs/inject/head.html`.
- Logo / favicon : `static/logo.svg`, `static/favicon.svg` — paramètres `BookLogo` et `BookFavicon` dans `hugo.toml`.
- Liens vers les Markdown du dépôt : shortcode `{{< ghdoc "chemin/relatif/depuis/documentation/" >}}` et paramètre `githubBlobBase` dans `hugo.toml`.
- **Synchronisation des tableaux ADR/WA/OST/PRD** depuis `documentation/INDEX.md` :

```bash
# À la racine du dépôt
python3 hugo-docs/scripts/sync_architecture_from_index.py
```

Le workflow **Deploy Hugo to GitHub Pages** exécute ce script avant `hugo build` ; un push sur `documentation/INDEX.md` déclenche aussi le déploiement.
- Configuration : `hugo.toml` (`baseURL` à aligner avec l’URL GitHub Pages du dépôt).

## Déploiement (GitHub Pages)

1. Sur GitHub : **Settings → Pages → Build and deployment** : source **GitHub Actions** (pas « Deploy from a branch » avec `gh-pages` sauf si vous changez de stratégie).
2. Le workflow `.github/workflows/hugo-pages.yml` construit le site et publie l’artefact Pages sur les pushes vers `main` / `master` qui touchent `hugo-docs/`.

Si l’URL du dépôt change, mettre à jour `baseURL` dans `hugo.toml` (format `https://<user>.github.io/<repo>/`).
