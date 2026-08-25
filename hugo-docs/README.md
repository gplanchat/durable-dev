# Hugo site (Durable user guide)

Publishes **end-user** documentation from **`../documentation/user/`** with **Hugo** + **hugo-book**.

ADRs and WAs are **not** part of this site; they live under `../documentation/adr/` and `../documentation/wa/`.

See **`../documentation/HUGO.md`**. Quick start: `hugo server` from this directory.

## Publication

Le site de référence est **https://durable.rocks/**, servi par un hébergement mutualisé OVH.
Le workflow `.github/workflows/docs-ovh.yml` construit et téléverse par FTPS à chaque push sur
`main` touchant `hugo-docs/` ou `documentation/user/`.

### Secrets et variables attendus

| Nom | Type | Rôle |
|---|---|---|
| `OVH_FTP_SERVER` | secret | hôte FTP de l'hébergement (`ftp.cluster0XX.hosting.ovh.net`) |
| `OVH_FTP_USERNAME` | secret | identifiant FTP |
| `OVH_FTP_PASSWORD` | secret | mot de passe FTP |
| `OVH_FTP_ROOT` | variable *(optionnel)* | racine web, `./www/` par défaut |

### Deux gardes dans le workflow

- **Quota.** L'hébergement est plafonné ; le job échoue si le site dépasse `SITE_QUOTA_KB`
  plutôt que de téléverser à moitié et laisser un site cassé en ligne.
- **Élagage.** Le thème embarque mermaid, katex et asciinema — 4,4 Mo copiés dans la sortie même
  inutilisés. Ils sont retirés avant l'envoi, ce qui fait passer le site de 4,9 Mo à ~584 Ko.
  Une garde échoue le build si une page se met à utiliser l'un de ces shortcodes, pour que
  l'élagage ne casse rien en silence.

GitHub Pages reste disponible en publication manuelle (`hugo-docs.yml`), non déclenchée
automatiquement.
