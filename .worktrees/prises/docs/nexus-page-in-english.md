# docs/nexus-page-in-english

- **Work item**: `nexus/` still names the demonstration in French — `facturation`, `verifier`,
  `encaisser`, `livraison`, `LivraisonHandler`, the `demo-boutique` and `demo-metier` namespaces,
  and two code snippets quoted from classes that #290 renames. A reader who greps the repository
  for any of them finds nothing. `comparison/` carries one French class name in its French page,
  where its English twin already says `Charge`.
- **Entry points**: `documentation/user/nexus/_index.md` and `_index.fr.md`,
  `documentation/user/comparison/_index.fr.md`. Names only, no prose rewrite, no source change.
  Depends on #290: until that merges, these pages would describe symbols the repository has not
  got. Touches the same files as #286 and #288, which will need a trivial rebase whichever lands
  second.
- **State**: in review — PR #291.
