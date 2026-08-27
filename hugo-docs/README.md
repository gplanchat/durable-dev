# Hugo site (Durable user guide)

Publishes **end-user** documentation from **`../documentation/user/`** with **Hugo** + **hugo-book**.

ADRs and WAs are **not** part of this site; they live under `../documentation/adr/` and `../documentation/wa/`.

See **`../documentation/HUGO.md`**. Quick start: `hugo server` from this directory.

## Publication

Le site de référence est **https://durable.rocks/**, servi par un hébergement mutualisé OVH.
Le workflow `.github/workflows/docs-ovh.yml` construit et téléverse par SFTP (`lftp mirror`) à chaque push sur
`main` touchant `hugo-docs/` ou `documentation/user/`.

### Secrets et variables attendus

| Nom | Type | Rôle |
|---|---|---|
| `OVH_FTP_SERVER` | secret | hôte SFTP de l'hébergement (`sshXXX.cluster0XX.hosting.ovh.net`) |
| `OVH_FTP_USERNAME` | secret | identifiant SSH/SFTP |
| `OVH_FTP_PASSWORD` | secret | mot de passe SSH/SFTP |
| `OVH_FTP_ROOT` | variable *(optionnel)* | racine web, `./www/` par défaut |
| `OVH_SSH_KNOWN_HOSTS` | secret *(optionnel)* | sortie de `ssh-keyscan -H <hôte>` ; sans lui, l'empreinte est acceptée à l'aveugle |

L'accès SSH/SFTP doit être **activé dans l'espace client OVH** (Hébergements → FTP-SSH) : il ne
l'est pas par défaut sur tous les plans. Le transfert efface les fichiers distants absents du
build (`mirror --delete`) : la racine web n'héberge que ce site, tout ce qui s'y trouve est
supposé venir de `hugo-docs/`. Le `.ftp-deploy-sync-state.json` laissé par
l'ancien déploiement FTPS est emporté par le premier `--delete`.

### Deux gardes dans le workflow

- **Quota.** L'hébergement est plafonné ; le job échoue si le site dépasse `SITE_QUOTA_KB`
  plutôt que de téléverser à moitié et laisser un site cassé en ligne.
- **Élagage.** Le thème embarque mermaid, katex et asciinema — 4,4 Mo copiés dans la sortie même
  inutilisés. Ils sont retirés avant l'envoi, ce qui fait passer le site de 4,9 Mo à ~584 Ko.
  Une garde échoue le build si une page se met à utiliser l'un de ces shortcodes, pour que
  l'élagage ne casse rien en silence.

### `.htaccess`

`hugo-docs/static/.htaccess` est publié à la racine du site et porte la redirection HTTP → HTTPS,
les en-têtes de sécurité, la compression et le cache. Chaque directive est protégée par
`<IfModule>` : sur un mutualisé, une directive portant sur un module absent renvoie **500 pour tout
le site**, pas seulement pour la page concernée.

Le `max-age` HSTS est volontairement court (300 s) au démarrage. HSTS est difficile à défaire — le
navigateur refuse `http://` pendant toute la durée annoncée, même si le certificat expire. Le
passer à un an une fois le domaine stable.
