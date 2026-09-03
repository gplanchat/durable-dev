# docs/comparaison-worker-multi-hotes

- **Chantier** : §1 de `documentation/user/comparison/` suppose encore Symfony seul. Depuis, il y a
  `durable-laravel` et `durable-magento` — et Magento n'est pas un consommateur de file, ses workers
  sont des commandes `bin/magento`. La phrase « delivered by Symfony Messenger » et les trois lignes
  du tableau sous elle sont à rendre agnostiques de l'hôte.
- **Entrées** : `documentation/user/comparison/_index.md` uniquement (§1 et le verdict final).
  Ne touche pas `packages/` — la NOTE périmée « There is no Laravel integration package yet » du
  bridge Illuminate est une édition à part.
- **État** : rédaction.
