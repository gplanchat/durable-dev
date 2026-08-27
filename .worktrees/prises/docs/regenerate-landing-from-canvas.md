# docs/regenerate-landing-from-canvas

Régénération de `hugo-docs/layouts/index.html` depuis le canevas, après quinze
tours de refonte : passe « fun », quinze puces ordonnées, interrupteurs
`release*`, socles d'Aimeos, marques à 48 px, gardes mobiles.

**Empilée sur `feat/logo-typo3` (PR #126)** : la régénération a besoin de
`typo3.svg` et du motif `[a-z0-9-]+`, sinon l'import s'arrête. À fusionner
après elle.

Ne touche qu'à `layouts/index.html`. La page FR n'a pas de cible Hugo — c'est
un autre chantier (config multilingue, `index.fr.html`, trois chaînes en dur du
script à traduire).
