## Why

`demo-nexus-deux-applications` a mis deux maquettes en présence et les a fait s'appeler dans les
deux sens. Sa preuve est mesurée, et elle vaut pour ce qu'elle montre : **Symfony et Sylius**, deux
applications du même framework, deux namespaces, chacune appelante et servante.

Ce que cette preuve ne dit pas, c'est ce que Nexus demande à l'**hôte**. Les deux maquettes
partagent le conteneur de Symfony, la passe de compilation qui enregistre les gestionnaires, et le
transport Messenger qui tourne les workers. Un lecteur en droit de conclure que Nexus est une
fonctionnalité du bundle Symfony n'aurait rien lu de travers.

Le dépôt a une troisième maquette, et elle n'a **rien** de tout cela : `magento/` câble ses services
en `di.xml`, tourne ses workers par une commande `bin/magento durable:worker`, et lit son DSN dans
`app/etc/env.php`. Si elle appelle une opération servie par les deux autres sans qu'on ajoute une
ligne au cœur, la démonstration dit quelque chose que deux applications Symfony ne pouvaient pas
dire.

Et une réserve attend d'être levée : `magento-module` §3bis.9 écarte explicitement le cas Nexus de
`EveryCaseWorkflow` — *« Nexus demande deux applications, qui appartiennent à
`change/demo-nexus-deux-applications` »*. Elles existent maintenant.

## What Changes

La maquette Magento rejoint la démonstration, sur un troisième namespace `demo-magento`, **en
appelante seulement** :

- elle appelle `stock/reserver`, servie **tout de suite** par la boutique Sylius ;
- elle appelle `facturation/verifier` puis `facturation/encaisser`, la seconde remplie par un
  **workflow** du métier Symfony.

Un seul workflow, dans le module de banc `Gplanchat_DurableProbe`, appelle les deux services — donc
les deux formes de réponse et les deux endpoints existants, depuis un hôte qui n'est pas Symfony.

### Pourquoi appelante seulement

Servir demande à l'hôte d'enregistrer des gestionnaires et de poller une file Nexus : côté Symfony
c'est `NexusHandlerPass` et un transport Messenger, et Magento n'a ni l'un ni l'autre. Appeler ne
demande **rien** — `WorkflowEnvironment::nexusStub()` lit le contrat par réflexion, et
`WorkflowTaskRunner` est déjà le worker que les trois hôtes partagent.

C'est précisément ce déséquilibre qui est la démonstration : le côté appelant est gratuit partout,
le côté servant se câble une fois par hôte. Faire servir Magento demanderait un chantier dans le
module — il aura son change, et il commencera là où celui-ci s'arrête.

### Ce que le banc Magento doit gagner

Le contrat partagé, un workflow, une commande pour le démarrer, et un DSN qui pointe le cluster de
la démonstration. Sa propre grappe (`temporalio/auto-setup:1.25.2` dans son `compose.yaml`) répond
`Nexus APIs are disabled` — c'est déjà écrit dans `demo/README.md`, et c'est la raison pour laquelle
la démonstration a son `temporal server start-dev` à elle.

## Impact

- `magento/` : le contrat en dépôt path, un workflow et une commande dans `Gplanchat_DurableProbe`.
- `bin/demo-nexus` : un troisième namespace, et **aucun endpoint de plus** — un endpoint désigne qui
  sert, et Magento ne sert pas.
- `demo/lancer.sh` et `demo/README.md` : un processus de plus, et le compte refait.
- `documentation/user/nexus/` : la section « Deux applications, en vrai » devient trois, dans les
  deux langues.
- Aucun changement aux paquets publiés : ni le cœur, ni le pont Temporal, ni `durable-magento`. Si
  l'un d'eux doit changer, c'est que l'hypothèse ci-dessus est fausse, et la §0 le dira avant que
  quoi que ce soit ne soit écrit.
