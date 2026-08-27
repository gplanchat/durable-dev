# feat/landing-fr

Câblage de la page d'accueil française : `/` reste anglais, `/fr/` devient
français. Configuration multilingue Hugo, `layouts/index.fr.html` régénéré
depuis le canevas, et `import-design.py` rendu conscient de la langue.

Quatre chaînes anglaises sont injectées par le script lui-même — `Dark`,
`Hover any line`, la phrase par défaut du panneau d'annotation, et la paire
JSON qui alimente le script d'annotation. Elles atterrissaient telles quelles
dans la page française.

La doc reste anglaise et n'est **pas** montée sous `/fr/` : dupliquer l'arbre
ferait un second exemplaire complet sous le quota de l'hébergement, pour servir
de l'anglais à des URL françaises.

Le sélecteur de langue vient du canevas, pas d'une retouche du fichier généré —
sinon il disparaît à la régénération suivante.
