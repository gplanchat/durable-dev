# docs/guide-flex-contrib-and-doctrine-messenger

- **Scope**: #443 — the getting-started guide (EN, FR) says what a reader does about the bundle's
  contrib recipe, and what the recipe writes that the guide replaces; `bin/guide-follows.sh` takes the
  step from the guide instead of setting `allow-contrib` silently. #445 — reproduce the DBAL profile
  on a fresh skeleton; if `doctrine://` fails, add `symfony/doctrine-messenger` to the guide's require
  line (EN, FR).
- **Entries**: `documentation/user/getting-started/_index.md`, `documentation/user/getting-started/_index.fr.md`,
  `bin/guide-follows.sh`, `bin/guide-follows/`.
- **Done when**: the guide names the step in both languages; `bin/guide-follows.sh` passes on both
  guides and fails when the step is removed from one; #445 reproduced (or not) on a fresh skeleton and
  the result recorded; the guide checked on a minified build served over HTTP.
- **State**: in review — PR #537, bob, reviewers vera (docs) and antoine.
