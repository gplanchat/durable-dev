# Hugo documentation site

The static site under **`hugo-docs/`** publishes **user documentation** only: prose for people **using** the Durable component (guides, concepts, getting started). It is built with **[Hugo](https://gohugo.io/)** and the **[hugo-book](https://github.com/alex-shpak/hugo-book)** theme.

## What the site is *not*

- It is **not** a mirror of **ADRs** (`documentation/adr/`, prefix **DUR**) or **working agreements** (`documentation/wa/`). Those remain **contributor-facing** records in the repository.
- It does **not** replace `documentation/INDEX.md` or `LIFECYCLE.md`, which describe how the team manages documents.

## Source of the user site

| Role | Location |
|------|-----------|
| **User guide (Hugo)** | `documentation/user/` — edit Markdown here; the next `hugo` build updates the site. |
| **Architecture / process** | `documentation/adr/`, `documentation/wa/`, `INDEX.md`, `LIFECYCLE.md` — stay in Git; link from the repo or from prose in `documentation/user/` when users need pointers. |

## Mount

`hugo-docs/hugo.toml` mounts **`../documentation/user`** → **`content/docs/`** (single tree). Add new sections as subfolders under `documentation/user/` with `_index.md` files.

The guide is bilingual. Each page has a French sibling, `<page>.fr.md` next to `<page>.md` (21 of each today), which Hugo renders under **`/fr/docs/`**. The English stays at the root. Hugo does not fall back from one language to the other, so a page with no `.fr.md` sibling does not exist under `/fr/docs/`.

## Local build

Prerequisites: **Hugo Extended** (see CI version in `.github/workflows/docs-ovh.yml`).

```bash
cd hugo-docs
hugo server
```

Production build:

```bash
cd hugo-docs
hugo --minify --gc
```

Output: `hugo-docs/public/` (ignored by Git).

## `llms.txt`

The build also writes `/llms.txt`, an index of the user guide for coding agents and for Context7
(#253). `layouts/index.llms.txt` builds it from the guide's own sections, so a new page appears in
it without an edit. The French home has none: it is an index for coding agents, which read the English guide. The repository's
root `context7.json` tells Context7 which folder to read if the repository itself is submitted.

## Screenshots of the dashboards

The images of `documentation/user/dashboard/` live in `hugo-docs/static/images/dashboard/` and are
referenced by `/images/dashboard/<name>.png`. They are real captures of the benches, never
retouched, at 1280 pixels wide, light theme, each under 200 kB. A surface with a French interface
(Sylius, Filament) has a `.fr.png` sibling, and its French page uses it.

To reshoot one, run the bench of the surface over runs that a real worker executed: a completed
order, a failed one (payment declined), one suspended on a timer after its first task waited in the
queue for a few seconds (the hatched stretch), and one dispatched with no worker at all.

- **Sylius** (`sylius/`): PHP 8.3 and a private PostgreSQL 16, the DBAL backend, `durable:setup`
  after `doctrine:schema:update`, an administrator per locale. The order workflow of the bench calls
  Nexus, which the SQL journal does not serve: use a workflow without Nexus.
- **Filament**: a copy of `laravel/` with `filament/filament`, the plugin and the Illuminate backend
  on SQLite, a panel with a login.
- **Magento** (`magento/`): the bench over MySQL 8.4, OpenSearch and a Temporal dev server, the
  probes of the bench as seeds. The grid is shot at a CSS zoom of 0.9 so that the rows fit, within 120 seconds of stopping the workers so that the banner reports them.
- **Web profiler** (`symfony/`): a request that dispatches a workflow, then `/_profiler/<token>?panel=durable`.

Shoot with Chromium through puppeteer-core. Chromium is a snap here and cannot write under a hidden
folder such as `.claude/`: write the PNG under a visible folder, then move it.

## Deployment configuration

In `hugo-docs/hugo.toml`, set **`baseURL`** to the real site URL and adjust **`params.BookRepo`** / **`BookEditPath`** if the default fork or branch differs.

## References

- [WA001 — English language for project documentation](wa/WA001-english-language-documentation.md)
- [documentation/INDEX.md](INDEX.md)
