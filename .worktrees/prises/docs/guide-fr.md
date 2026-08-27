# docs/guide-fr

Traduction française du guide utilisateur : 14 fichiers, ~17 000 mots, sous
`documentation/user/**/*.fr.md`. Hugo traduit par nom de fichier, le montage
existant sert déjà les deux langues — une page sans `.fr.md` n'existe
simplement pas en français.

**Empilée sur `feat/landing-fr` (PR #140)**, qui pose la configuration
multilingue. À fusionner après elle.

Deux pièges :
- les ancres. Huit sont citées d'un fichier à l'autre ; traduire les titres les
  déplacerait. Les titres visés portent une ancre explicite `{#slug-anglais}`.
- les liens de la page d'accueil vers `/docs/…`. Depuis la version française
  ils doivent viser `/fr/docs/…`, sinon la page française renvoie au guide
  anglais alors qu'il existe en français.
