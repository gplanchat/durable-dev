# change/magento-module

Ouverture du chantier Magento : le change OpenSpec seul — `proposal.md`,
`design.md`, `tasks.md`. Pas une ligne de PHP, les tranches suivront en TDD.

Trois choses que l'ouverture doit trancher, et que rien ne tranche aujourd'hui :

- **Le nom du paquet.** Le site affiche `gplanchat/durable-magento?`, le banc
  déclare `gplanchat/durable-module`, et la convention Magento voudrait
  `gplanchat/module-durable`. Trois réponses différentes dans le dépôt.
- **Le banc n'est pas au dépôt.** `git ls-files magento` rend zéro, quand
  `sylius/` en rend 220. OST004 dit « bench already in `magento/` » : c'est vrai
  sur ce poste et faux pour quiconque clone.
- **Le backend.** Le site promet in-memory et Temporal seulement, avec sa
  raison — Magento n'embarque aucune des deux couches SQL. Le change s'y tient
  ou il élargit, mais il le dit.
