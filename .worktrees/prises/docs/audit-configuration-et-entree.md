# docs/audit-configuration-et-entree

- **Chantier** : les 10 constats restants de l'audit de doc (les 3 autres sont tombés avec la
  #267). Le plus grave : `configuration/` annonce `activity_transport.type` par défaut à
  `messenger` alors que `Configuration.php:49` et `DurableExtension.php:441` disent tous deux
  `in_memory` — clé omise, les activités s'exécutent en synchrone dans la tâche de workflow.
  Suivent DBAL absent de la page qui promet toutes les clés, `getting-started/` qui n'offre que
  Symfony, deux divergences EN/FR réelles (bloc `di.xml` manquant en français ; identifiants Nexus
  traduits, or ce sont les noms sur le fil), et un lot de petites corrections.
- **Entrées** : `documentation/user/configuration/`, `getting-started/`, `packages/`, `nexus/`,
  `deploying/`, `comparison/` — les deux langues — et `hugo-docs/layouts/index.html` pour le `?`
  manquant de TYPO3. Aucun code de `src/` : la doc s'aligne sur le code, jamais l'inverse.
- **État** : rédaction.
