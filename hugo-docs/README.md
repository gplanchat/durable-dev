# Hugo site (Durable user guide)

Publishes **end-user** documentation from **`../documentation/user/`** with **Hugo** + **hugo-book**.

ADRs and WAs are **not** part of this site; they live under `../documentation/adr/` and `../documentation/wa/`.

See **`../documentation/HUGO.md`**. Quick start: `hugo server` from this directory.

## Publication

The reference site is **https://durable.rocks/**, served by OVH shared hosting.
The `.github/workflows/docs-ovh.yml` workflow builds and uploads over SFTP (`lftp mirror`) on every push to
`main` that touches `hugo-docs/` or `documentation/user/`.

### Expected secrets and variables

| Name | Type | Role |
|---|---|---|
| `OVH_FTP_SERVER` | secret | SFTP host of the hosting (`sshXXX.cluster0XX.hosting.ovh.net`) |
| `OVH_FTP_USERNAME` | secret | SSH/SFTP login |
| `OVH_FTP_PASSWORD` | secret | SSH/SFTP password |
| `OVH_FTP_ROOT` | variable *(optional)* | web root, `./www/` by default |
| `OVH_SSH_KNOWN_HOSTS` | secret *(optional)* | output of `ssh-keyscan -H <host>`; without it, the fingerprint is accepted blindly |

SSH/SFTP access must be **enabled in the OVH customer area** (Hosting → FTP-SSH): it is
not enabled by default on every plan. The transfer deletes remote files missing from the
build (`mirror --delete`): the web root hosts only this site, everything found there is
assumed to come from `hugo-docs/`. The `.ftp-deploy-sync-state.json` left behind by
the old FTPS deployment is swept away by the first `--delete`.

### Two guards in the workflow

- **Quota.** The hosting is capped; the job fails if the site exceeds `SITE_QUOTA_KB`
  rather than uploading halfway and leaving a broken site online.
- **Pruning.** The theme bundles mermaid, katex and asciinema: 4.4 MB copied into the output even
  when unused. They are removed before upload, which brings the site from 4.9 MB down to ~584 KB.
  A guard fails the build if a page starts using one of these shortcodes, so that the
  pruning does not silently break anything.

### `.htaccess`

`hugo-docs/static/.htaccess` is published at the site root and carries the HTTP → HTTPS redirect,
the security headers, compression and caching. Every directive is wrapped in
`<IfModule>`: on shared hosting, a directive for a missing module returns **500 for the whole
site**, not only for the page concerned.

The HSTS `max-age` is deliberately short (300 s) at launch. HSTS is hard to undo: the
browser refuses `http://` for the whole announced duration, even if the certificate expires.
Raise it to one year once the domain is stable.
