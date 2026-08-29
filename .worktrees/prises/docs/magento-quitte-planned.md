# docs/magento-quitte-planned

La tâche **6.2** de `change/magento-module`, prise à part : elle ne touche que
`hugo-docs/`, et le worktree du chantier est resté au niveau de `main`.

Deux attributs dans chacun des deux canevas `.dc.html`, puis
`import-design.py` sur les deux — quatre lignes de diff en tout dans les pages
générées, ce qui est la preuve que WA005 attendait : le canevas et les pages
étaient réellement en phase.

⚠ **À ne pas fusionner avant que `gplanchat/durable-magento` soit sur
Packagist.** Le satellite est public et poussé, mais `repo.packagist.org` répond
404 : le sélecteur proposerait une commande qui ne résout pas, et la page
d'accueil contredirait la page Packages, qui porte encore son avertissement.
