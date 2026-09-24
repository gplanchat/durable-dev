# fix/docs-ovh-known-hosts

- **Scope**: #354 (P-20). `docs-ovh.yml` fails when `OVH_SSH_KNOWN_HOSTS` is unset instead of
  falling back to `ssh-keyscan` and sending the SFTP password to an unverified host.
- **Entries**: `.github/workflows/docs-ovh.yml` (supervised path, human commit).
- **Prerequisite**: `OVH_SSH_KNOWN_HOSTS` set on the `durable.rocks` environment, fingerprint
  checked against OVH's, before the merge — it is not set today.
- **State**: in review, PR #539.
