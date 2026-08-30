# Tasks

## 0. Sonder avant de construire

- [ ] 0.1 **Le PHP du banc Magento porte-t-il `ext-grpc` ?** Le banc n'a pas de conteneur PHP : son
      `compose.yaml` ne monte que MySQL, OpenSearch, Redis et une grappe Temporal, et `bin/magento`
      tourne sur le PHP de l'hôte. C'est donc le tableau de §0.1 du change à deux applications qui
      décide, et il donne une réponse différente de celle de Sylius — le banc annonce PHP 8.2, et
      8.2 est justement la version complète du poste.
- [ ] 0.2 **Appeler est-il vraiment sans hôte ?** Lu : `WorkflowEnvironment::nexusStub()` résout le
      contrat par réflexion et ne touche aucun conteneur, et `WorkflowTaskRunner` est la même classe
      pour les trois hôtes (`TemporalJournalTransport`, `RuntimeFactory::journalWorker()`,
      `DurableServiceProvider`). Lu n'est pas mesuré : la preuve est une exécution qui aboutit
      depuis `bin/magento durable:worker --role=journal`, et elle appartient à §5.
- [ ] 0.3 **La grappe du banc ne convient pas.** `temporalio/auto-setup:1.25.2` répond
      `Nexus APIs are disabled`. Le banc doit donc pointer le `temporal server start-dev` de la
      démonstration, et son `app/etc/env.php` porte un DSN qui nomme `demo-magento`.

## 1. Le contrat partagé, troisième consommateur

- [ ] 1.1 `src/DurableDemoContracts/` déclaré en dépôt path par `magento/composer.json`, et le
      `composer.lock` remis à jour dans le même commit — la CI installe par `composer install`, qui
      refuse un lock en retard sur son `composer.json`.
- [ ] 1.2 ⚠ **Le garde de nommage n'existe pas de ce côté.** La règle « tout paramètre de workflow
      sans valeur par défaut doit être un paramètre du contrat » vit dans `NexusHandlerPass`, donc
      dans le conteneur de Symfony. Magento n'en hérite pas, et l'appelant n'en a de toute façon pas
      besoin — c'est le **servant** que la passe garde. Le dire là où on le lirait, ne pas écrire un
      second garde pour un hôte qui ne sert pas.

## 2. Le workflow appelant

- [ ] 2.1 Un workflow dans `Gplanchat_DurableProbe` — le module de banc, pas le paquet publié —
      qui prend deux stubs typés sur les deux contrats et les deux endpoints existants, appelle
      `reserver`, puis `verifier` et `encaisser`. Les deux formes de réponse dans un seul workflow,
      depuis un hôte qui n'est pas Symfony.
- [ ] 2.2 La commande qui le démarre **sur la grappe** : `MagentoRuntime::run()` exécute ici et
      maintenant, ce qui n'est pas ce que la démonstration montre. C'est
      `RuntimeFactory::workflowClient()` qui est la porte.
- [ ] 2.3 Le workflow est déclaré dans le `di.xml` du banc : le conteneur de Magento n'a pas les
      tags de Symfony, rien ne ramasse `#[AsWorkflow]` tout seul.

## 3. Le cluster

- [ ] 3.1 `bin/demo-nexus` crée le namespace `demo-magento`. **Aucun endpoint de plus** : un
      endpoint dit où un service est servi, et Magento ne sert rien. En créer un
      `demo-magento-quelque-chose` que personne ne pollerait donnerait un appelant qui attend pour
      rien — l'exacte panne que le script existe pour éviter.

## 4. Faire tourner la démonstration

- [ ] 4.1 `demo/lancer.sh` démarre le worker de journal du banc Magento. **Recompter les
      processus** : cinq deviennent six, et pas sept — le workflow de Magento n'a pas d'activité,
      donc pas de worker d'activité, exactement comme la boutique.
- [ ] 4.2 Le script imprime le troisième appel avec les bonnes valeurs, et `demo/README.md` porte
      les prérequis du banc Magento : le DSN dans `env.php`, et le fait que sa propre grappe ne
      convient pas.

## 5. Éprouver

- [ ] 5.1 Trois processus, trois namespaces, un tableau de mesures : ce que chaque appel a rendu et
      combien de temps il a pris. Le repère du change précédent tient — `verifier` en quelques
      centaines de millisecondes, `encaisser` en une quinzaine de secondes, dont douze de minuteur.
- [ ] 5.2 Et la mesure qui vaut le chantier : **l'historique de l'exécution Magento** porte les
      événements `NexusOperationScheduled` / `Started` / `Completed`. C'est ce que le §3bis.9 de
      `magento-module` avait écarté faute de deux applications, et c'est ce que le tableau de bord
      Magento aura à montrer — le montrer appartient à `change/dashboard-presentation`, le produire
      appartient à ici.

## 6. Le dire

- [ ] 6.1 `demo/README.md` : trois maquettes, le compte des processus refait, et le troisième
      namespace.
- [ ] 6.2 `documentation/user/nexus/_index.{md,fr.md}` : la section « Deux applications, en vrai »
      en accueille une troisième, et dit ce qu'elle prouve de plus — appeler ne demande rien à
      l'hôte, servir se câble une fois par hôte. Vérifié sur un build `--minify` servi en HTTP.
