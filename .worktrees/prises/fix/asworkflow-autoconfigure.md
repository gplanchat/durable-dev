# fix/asworkflow-autoconfigure

- **Chantier** : M17 de l'audit / issue #255. `#[AsWorkflow]` existe et `WorkflowDefinitionLoader`
  le lit, mais `DurableBundle::build()` ne l'autoconfigure pas : trois attributs sur quatre le sont,
  et les workflows se balisent à la main par répertoire. L'issue annonce un piège — un workflow est
  instancié par réflexion avec un `WorkflowEnvironment` qui n'est pas un service — à éprouver plutôt
  qu'à supposer.
- **Entrées** : `DurableBundle::build()`, un test de conteneur, la documentation qui décrit la
  balise. Pas de changement du `WorkflowPass`.
- **État** : rédaction.
