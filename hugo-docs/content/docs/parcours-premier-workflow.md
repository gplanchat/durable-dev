---
title: "Parcours : installer et exécuter un premier workflow"
weight: 2
---

Ce parcours vous guide **pas à pas** jusqu’à voir un workflow **terminer avec succès** sur votre machine. Vous n’avez pas besoin de tout comprendre au début : l’objectif est de **voir le fil** (installation → schéma → commande → résultat), puis d’approfondir avec les autres pages.

## Ce que vous obtiendrez à la fin

- Un environnement Symfony avec le **bundle Durable** et les **tables** nécessaires (journal, métadonnées, etc.).
- L’exécution d’un **exemple** fourni avec le projet : un workflow de salutation qui appelle une **activité** pour composer le message.
- Une idée claire de **ce qui se passe** (sans encore maîtriser tous les détails du replay).

---

## Choisir votre point de départ

| Vous êtes… | Suivez… |
|------------|---------|
| **Contributeur** ou curieux du dépôt `durable-dev` | [Parcours A — monorepo](#parcours-a-depuis-le-monorepo) (recommandé pour reproduire exactement la doc). |
| **Intégrateur** dans votre propre projet Symfony | [Parcours B — projet existant](#parcours-b-dans-un-projet-symfony-existant) (aperçu ; le détail est dans [Installation du bundle]({{< relref "/docs/installation-bundle/" >}})). |

---

## Parcours A — depuis le monorepo

C’est le chemin le plus **direct** pour coller aux commandes de ce site et au README du dépôt.

### Étape 1 — Prérequis

- **PHP 8.2+** en ligne de commande (`php -v`).
- **Composer** (`composer --version`).
- Un clone du dépôt [durable-dev](https://github.com/gplanchat/durable-dev) (avec sous-modules si vous travaillez aussi sur la doc Hugo : `git submodule update --init --recursive`).

### Étape 2 — Entrer dans l’application exemple

L’équivalent d’un « mini-projet Symfony » vit dans le dossier **`symfony/`** à la racine du monorepo. C’est là que le bundle et les workflows d’exemple sont câblés.

```bash
cd symfony
composer install
```

Les paquets `gplanchat/durable` et `gplanchat/durable-bundle` sont résolus en **path** vers `../src/...` : vous exécutez le code du dépôt, pas seulement une version Packagist figée.

### Étape 3 — Créer les tables (journal Durable)

Durable persiste l’historique des exécutions en base (ici **SQLite** par défaut dans l’exemple). La commande est **idempotente** : vous pouvez la relancer sans casser un état déjà initialisé.

```bash
php bin/console durable:schema:init
```

**À retenir** : sans cette étape, le moteur n’a pas où écrire le journal des événements du workflow.

### Étape 4 — Lancer un premier workflow (sans worker long)

Pour une démonstration rapide, le projet expose une commande **`durable:sample`** qui enchaîne ce qu’il faut pour un run **simple** (voir le README racine du dépôt pour le détail).

```bash
php bin/console durable:sample GreetingWorkflow --name=Alice
```

Vous devriez voir un message du type **Hello, Alice!** (ou équivalent selon l’exemple).

**Ce qui s’est passé (vue simplifiée)** :

1. Le moteur a **démarré** (ou repris) une exécution de workflow du type `GreetingWorkflow`.
2. Le workflow a **demandé** une activité (contrat d’interface côté code) pour composer la phrase.
3. L’**implémentation** de l’activité a été invoquée (selon la config : même processus ou file d’attente, selon le mode).
4. Le **résultat** a été enregistré dans le journal et affiché.

Ce n’est pas encore la « prod complète » avec des workers séparés — mais vous avez vu le **cœur** : workflow + activité + persistance.

### Étape 5 (optionnel) — S’approcher d’une prod : workers Messenger

Quand vous passerez à des scénarios **distribués** (processus séparés pour workflows et activités), il faudra des **consommateurs** Symfony Messenger alignés sur vos transports (`durable_workflows`, `durable_activities`, etc.). Les commandes typiques ressemblent à :

```bash
php bin/console messenger:consume durable_workflows durable_activities -vv
```

Les noms exacts dépendent de votre `messenger.yaml`. Pour l’instant, retenez : **sans consommateur**, les messages d’activité ou de reprise ne sont pas traités.

---

## Parcours B — dans un projet Symfony existant

1. **`composer require gplanchat/durable-bundle`**
2. Activer le bundle, copier une configuration proche de [`durable.yaml` du dépôt](https://github.com/gplanchat/durable-dev/blob/main/symfony/config/packages/durable.yaml) (adaptée à vos transports et à votre connexion Doctrine).
3. Déclarer vos **workflows** et **handlers d’activités** (tags, `#[AsDurableActivity]`, etc.) — voir [Installation du bundle]({{< relref "/docs/installation-bundle/" >}}).
4. **`php bin/console durable:schema:init`**
5. Déclencher un workflow depuis **votre** code ou une commande (vous pouvez vous inspirer de `durable:sample` dans le monorepo).

---

## Relier ce parcours au reste de la doc

| Besoin | Page |
|--------|------|
| Comprendre **workflow vs activité**, déterminisme, `await` | [Workflows et activités]({{< relref "/docs/workflows-et-activites/" >}}) |
| **Configurer** Messenger, DBAL, workers en détail | [Installation du bundle Symfony]({{< relref "/docs/installation-bundle/" >}}) |
| **MySQL**, connexion dédiée, lectures non bufferisées | [DBAL : connexion et MySQL]({{< relref "/docs/dbal-et-mysql/" >}}) |
| **Temporal** comme backend de journal | [Temporal avec Durable]({{< relref "/docs/temporal/" >}}) |
| Décisions d’architecture (ADR, etc.) | [Architecture documentée]({{< relref "/docs/architecture/" >}}) |

---

[← Introduction]({{< relref "/docs/" >}})
