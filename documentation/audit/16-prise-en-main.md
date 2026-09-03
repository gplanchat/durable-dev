# Expérience du nouvel arrivant — structure documentaire, chemin du premier succès

## Synthèse

La page `why/` fait bien ce qu'on lui demande : elle explique **pourquoi** l'exécution durable avant tout **comment**, elle nomme les cas où l'on n'en a pas besoin, et elle est en tête de navigation — cet axe est sain, il n'y a pas de constat à y porter. Le reste du chemin d'arrivée casse en trois endroits : le premier workflow copié-collé depuis `getting-started/` ne s'enregistre pas (mauvais attribut), le parcours ne mène jamais à un résultat visible (le dernier appel rend `void`, aucun consommateur n'est indiqué pour le profil in-memory que la page prescrit elle-même), et la porte d'entrée du dépôt met Docker, MySQL 8.4 et PHP 8.3 avant la moindre ligne de workflow — alors que le backend mémoire sans infrastructure existe et est le défaut du bundle. La navigation Hugo est une liste plate de 17 sections triées par `weight`, où une page de référence de 434 lignes (`packages/`) précède le tutoriel et où `concepts/` arrive en huitième position, après trois pages d'exploitation et une comparaison produit. Il manque franchement un glossaire (« curseur » est dans l'argumentaire du README et n'est défini nulle part ; « Nexus » apparaît une fois sans définition alors qu'il a sa page), une page de dépannage, et une entrée de navigation vers `UPGRADE.md`.

## Constats

### C1 — Le premier workflow du guide ne s'enregistre pas : mauvais attribut d'activité
- **Fichier** : `documentation/user/getting-started/_index.md:183` (et `documentation/user/activities/_index.md:33`)
- **Gravité** : bloquant
- **Constat** : L'étape 2 du « First workflow » pose `#[AsActivity(name: 'greeting-activities')]` sur la classe d'implémentation. Le bundle n'autoconfigure que `#[AsActivityHandler(contract: …)]` (`src/DurableBundle/DurableBundle.php:25`), donc le gestionnaire n'est jamais enregistré et le workflow échoue à l'exécution (l'app d'exemple, elle, est correcte partout : `symfony/src/Durable/Activity/EncaissementActivityHandler.php:17`, et aucune implémentation de `symfony/src/` n'utilise `#[AsActivity]`). L'attribut est de surcroît sur la mauvaise classe : `ActivityContractResolver` lit `#[AsActivity]` sur le **contrat**, comme préfixe de nom (`src/Durable/Activity/ActivityContractResolver.php:62`).
- **Amont** : non sourcé (constat de fait, fondé sur le code : `src/DurableBundle/DurableBundle.php:25` et `src/Durable/Activity/ActivityContractResolver.php:62`).
- **Correctif** : remplacer l'attribut par `#[AsActivityHandler(contract: GreetingActivities::class)]` dans les deux pages, et si `#[AsActivity]` doit être montré, le poser sur l'interface en expliquant qu'il ne fait que préfixer le nom d'activité.

### C2 — Le parcours d'arrivée ne mène jamais à un résultat visible
- **Fichier** : `documentation/user/getting-started/_index.md:243`
- **Gravité** : bloquant
- **Constat** : La dernière étape appelle `dispatchNewWorkflowRun()`, qui rend `void` (`src/Durable/Port/WorkflowResumeDispatcher.php:25`) ; avec les DSN `in-memory://` que la page prescrit elle-même (`:104-105`), aucun consommateur ne tourne, et les seules commandes `messenger:consume` montrées sont la paire Temporal (`:258`, `:261`). Le lecteur repart avec un `executionId` et aucun moyen de voir « Hello ». Les deux commandes qui referment la boucle existent pourtant — `messenger:consume durable_workflows` (`symfony/README.md:77`) et `durable:sample`, qui draine les transports lui-même (`symfony/src/Command/RunDurableSampleCommand.php:132`) — mais aucune n'est citée dans le guide utilisateur, la seconde seulement en incise dans une section PHPUnit (`symfony/README.md:90`).
- **Amont** : `symfony/symfony-docs`, `quick_tour/the_big_picture.rst` — le lecteur atteint une page rendue dans le navigateur en une vingtaine de lignes, capture d'écran à l'appui, avant tout concept ; c'est la définition du tutoriel chez Diátaxis (https://diataxis.fr/tutorials/).
- **Correctif** : terminer « First workflow » par une étape 5 qui montre soit `php bin/console messenger:consume durable_workflows durable_activities`, soit `php bin/console durable:sample greeting --name=World`, avec la sortie attendue.

### C3 — La porte d'entrée du dépôt impose l'infrastructure lourde avant tout workflow
- **Fichier** : `README.md:23-34`
- **Gravité** : majeur
- **Constat** : Le premier bloc exécutable du README est le démarrage de la boutique Sylius — PHP 8.3, Docker Compose, MySQL 8.4, nginx, mailhog. L'argumentaire d'ouverture (`README.md:5`) annonce « coordinated with **Temporal** », et `README.md:45` place `ext-grpc` dans les prérequis. Le seul autre bloc exécutable (`:51-54`) lance la suite de tests, pas un workflow. Le backend mémoire, qui ne demande rien et qui est le **défaut du bundle**, n'apparaît qu'à `documentation/user/getting-started/_index.md:12` et `:49`.
- **Amont** : `symfony/symfony-docs`, `index.rst` — « Quick Tour » puis « Getting Started » précèdent « Topics », « Components » et « Reference Documents » ; le banc d'essai n'est jamais le premier pas.
- **Correctif** : ouvrir le README par le chemin sans infrastructure (installer, écrire un workflow, l'exécuter en mémoire) et déplacer le démarrage Sylius dans `sylius/README.md`, référencé depuis la table des paquets.

### C4 — Le README de l'app d'exemple enseigne une API qui n'existe pas, sur un service absent par défaut
- **Fichier** : `symfony/README.md:28`
- **Gravité** : majeur
- **Constat** : Le README présente `WorkflowClient` comme « the entry point for workflows from application code » et montre `$client->start('MyWorkflow', ['key' => 'value'])`. Aucune méthode `start()` n'existe : l'interface déclare `startAsync()` et `startSync()`, toutes deux avec un troisième argument obligatoire `$executionId` (`src/Bridge/Temporal/WorkflowClientInterface.php:24`, `:32`) — l'exemple `startSync` de la ligne 31 est donc lui aussi incomplet. Par ailleurs `WorkflowClientInterface` n'est alié qu'à l'intérieur du garde « DSN Temporal renseigné » (`src/DurableBundle/DependencyInjection/DurableExtension.php:338` → `:371`) : sur la configuration in-memory que le guide prescrit, ce point d'entrée n'est pas dans le conteneur.
- **Amont** : non sourcé (constat de fait, vérifié contre le code : `src/Bridge/Temporal/WorkflowClientInterface.php:24`, `:32` et `src/DurableBundle/DependencyInjection/DurableExtension.php:338`, `:371`).
- **Correctif** : corriger les signatures et indiquer explicitement que `WorkflowClient` appartient au pont Temporal ; pour le profil mémoire, renvoyer vers `WorkflowResumeDispatcher` (ou `durable:sample`).

### C5 — Navigation plate : de la référence avant le tutoriel, l'explication en huitième position
- **Fichier** : `documentation/user/packages/_index.md:3` (`weight: 5`) ; `hugo-docs/hugo.toml:54`
- **Gravité** : majeur
- **Constat** : Les 17 sections de `documentation/user/` sont toutes de profondeur 1, sans sous-page, triées uniquement par `weight` — le thème rend une liste de frères, sans regroupement. `packages/` (434 lignes, 8 paquets, matière de référence) est en position 2, avant `getting-started/` (`weight: 10`) ; `concepts/` (`weight: 20`) arrive après `backends` (15), `container-images` (16), `dashboard` (17) et `comparison` (18) — trois pages d'exploitation et une comparaison produit entre le tutoriel et le vocabulaire. Aucune progression tutoriel → guides → référence → concepts n'est matérialisée.
- **Amont** : https://diataxis.fr/ (les quatre modes doivent être séparés architecturalement) ; `symfony/symfony-docs`, `index.rst` : Quick Tour → Getting Started → Topics → Components → Reference Documents.
- **Correctif** : créer quatre sections Hugo (`tutorial/`, `guides/`, `reference/`, `concepts/`) et y ranger les pages existantes ; à défaut, remettre `concepts/` avant `packages/` et pousser `container-images`, `dashboard`, `deploying` et `comparison` en fin de liste.

### C6 — Aucun glossaire : « curseur » et « Nexus » sont employés avant d'être définis
- **Fichier** : `documentation/user/concepts/_index.md:321`
- **Gravité** : majeur
- **Constat** : « Nexus » apparaît exactement une fois dans la page de vocabulaire, dans une énumération de ce que Temporal sait faire, sans définition — alors qu'une page `nexus/` de 376 lignes est dans la navigation. « Curseur » est dans l'argumentaire d'ouverture du projet (`README.md:5`, « cursor-based event journal ») et n'apparaît nulle part dans `concepts/`. « Run » est le mot de l'API (`dispatchNewWorkflowRun`) et de tout le tableau de bord, sans être distingué de « workflow execution ». Il n'existe aucune page de glossaire dans `documentation/user/`.
- **Amont** : https://diataxis.fr/reference/ — le glossaire relève de la référence, consultable par lookup, et ne se substitue pas à l'explication en prose.
- **Correctif** : ajouter `documentation/user/glossary/_index.md` définissant run, exécution, activité, journal, curseur, rejeu, déterminisme, signal, requête, mise à jour, Nexus, et y renvoyer depuis `concepts/` et `why/`.

### C7 — Pages qui manquent franchement : dépannage, migration, et la démo introuvable
- **Fichier** : `documentation/user/nexus/_index.md:366`
- **Gravité** : mineur
- **Constat** : (a) Aucune page de dépannage, alors que la panne la plus probable est celle que C1 et C2 fabriquent — « j'ai dispatché, rien ne se passe ». (b) `UPGRADE.md` porte le renommage d'attributs qui invalide tous les exemples antérieurs à `v0.1.0-alpha8` (documenté à `documentation/INDEX.md:7-13`) mais vit à la racine du dépôt, hors de la navigation utilisateur : le nouvel arrivant qui copie un extrait ancien ne le trouvera pas. (c) La démo Nexus à quatre applications n'est liée que depuis `nexus/_index.md:366`, la page la plus avancée du guide, jamais depuis `README.md` ni depuis `documentation/user/_index.md`, et son `demo/README.md` est en français seul, contrairement à WA001 et au reste du guide qui suit le couple `.md`/`.fr.md`.
- **Amont** : `symfony/symfony-docs` — `setup/upgrade_major.rst` et `setup/upgrade_minor.rst` sont dans le corps de la documentation, pas dans un fichier de racine ; https://diataxis.fr/how-to-guides/ pour le dépannage comme guide orienté tâche.
- **Correctif** : ajouter une page « Troubleshooting » et une page « Upgrading » dans `documentation/user/`, et lier `demo/` depuis `README.md` et l'index du guide.

### C8 — L'index contributeur a décroché du contenu réel
- **Fichier** : `documentation/INDEX.md:95-101`
- **Gravité** : mineur
- **Constat** : L'index liste sept pages utilisateur (getting-started, backends, concepts, workflows, activities, testing, configuration) alors que `documentation/user/` en contient dix-sept. Manquent notamment `why/` — la page que `documentation/user/_index.md:9` désigne comme point de départ —, `packages/`, `comparison/`, `failures/`, `cancellation/`, `nexus/`, `options/`, `deploying/`, `dashboard/` et `container-images/`.
- **Amont** : non sourcé (cohérence interne entre `documentation/INDEX.md` et l'arborescence `documentation/user/`).
- **Correctif** : générer cette liste depuis l'arborescence, ou la réduire à un lien unique vers `documentation/user/_index.md` qui, lui, est à jour.
