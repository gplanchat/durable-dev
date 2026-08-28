# Tasks

## 0. Sonder avant de construire

- [x] 0.1 **`ext-grpc` et la joignabilité du cluster depuis la maquette Sylius.** La question qui
      décide la forme de la démonstration : la maquette tourne sur `ghcr.io/sylius/sylius-php:8.3-alpine`,
      où il n'y a pas de grpc, mais son README documente `symfony serve` et sa CI l'exécute **sur
      l'hôte** avec `setup-php`. C'est ce second chemin qui compte.
      **Mesuré, et le résultat est un chemin étroit :**

      | PHP | grpc | curl | intl | pdo_mysql |
      |---|---|---|---|---|
      | 8.2 | ✅ | ✅ | ✅ | ✅ |
      | **8.3** | ✅ | ❌ | ✅ | ❌ |
      | 8.4 | ❌ | ✅ | ❌ | ❌ |

      La maquette **exige PHP ≥ 8.3** dans son propre `composer.json` — pas seulement par une
      dépendance de test —, donc 8.2 est exclu malgré son équipement complet. En 8.3 il manque
      `curl`, réclamé par `stripe/stripe-php` et le pilote Chrome : deux paquets que la
      démonstration n'exécute pas. L'installation passe avec `--ignore-platform-req=ext-curl`.
      **Et la sonde aboutit** : depuis l'autoload de la maquette, `DescribeNamespace` répond
      `default`, `ext-grpc` est chargée, PHP 8.3.33. Le banc peut parler au cluster.
- [x] 0.2 **Le pont Temporal était déjà déclaré en dépôt path** par `sylius/composer.json` ; seul
      son `require` ne le listait pas. L'ajouter est une ligne, pas un montage.

## 1. Le contrat partagé

- [ ] 1.1 Un paquet `src/DurableDemoContracts/`, déclaré en dépôt path par les deux maquettes et
      **délibérément non publié** : deux applications de démonstration le consomment, en faire un
      paquet publiable traînerait la liste de contrôle SPLITS et le PAT pour rien. Son README le dit.
- [ ] 1.2 Le contrat `stock`, en deux interfaces : `StockServed` (`reserver`, immédiate) et
      `StockContract`, qui l'étend. Pour l'instant elles portent la même opération — la séparation
      existe pour ce que 2.x y ajoutera.
- [ ] 1.3 Le contrat `facturation` : `FacturationServed` (`verifier`, immédiate) et
      `FacturationContract extends FacturationServed`, qui ajoute `encaisser`, remplie par un
      workflow.
      ⚠ **Les noms de paramètres du contrat et ceux du workflow qui remplit l'opération doivent
      coïncider** — `mapInputToArguments` associe par nom. Renommer d'un seul côté donne `null`, en
      silence.

## 2. Sens 1 — Symfony appelle, Sylius sert (la forme immédiate)

Le sens le plus simple d'abord : il isole le risque d'infrastructure de celui des workers, la forme
immédiate n'exigeant aucun workflow qui remplit l'opération.

- [ ] 2.1 La maquette Sylius gagne son profil Temporal — namespace `demo-boutique` — sans que le
      backend DBAL de son tableau de bord ne change de rôle.
- [ ] 2.2 `StockHandler` dans `sylius/`, `#[AsNexusServiceHandler]`, qui répond depuis le modèle de
      la boutique.
- [ ] 2.3 Le DSN Temporal de `symfony/` est activé — namespace `demo-metier`. Sa configuration a
      `temporal.dsn: null` aujourd'hui : « Temporal sur les maquettes » vaut pour les deux.
- [ ] 2.4 Un workflow appelant dans `symfony/`, qui prend le stub typé et attend le verdict.
- [ ] 2.5 L'endpoint `demo-boutique-stock`, créé par un script d'opérateur.
- [ ] 2.6 Éprouvé pour de vrai : deux processus, deux namespaces, le verdict revient.

## 3. Sens 2 — Sylius appelle, Symfony sert (la forme différée)

- [ ] 3.1 `EncaissementWorkflow` dans `symfony/`, `#[FulfilsNexusOperation]`, avec une activité et
      un délai — pour que l'attente soit réelle et non simulée.
- [ ] 3.2 Un workflow de commande dans `sylius/` qui appelle `facturation/encaisser`.
- [ ] 3.3 L'endpoint `demo-metier-facturation`.
- [ ] 3.4 Éprouvé : la boutique ne tient rien d'ouvert pendant que le workflow avance en face.

## 4. Faire tourner la démonstration

- [ ] 4.1 **Compter les processus avant de promettre.** La forme différée n'avance que si un worker
      de tâches de workflow poll du côté servant — les tests d'intégration les pilotaient à la main.
      La démonstration en demande donc, par maquette : un worker Nexus, un worker de workflow, un
      worker d'activité. **Six processus.** C'est la différence entre une démonstration qui tourne
      et une qui reste suspendue sans rien dire.
- [ ] 4.2 Un script unique qui démarre ce qu'il faut et raconte ce qui se passe, plutôt que six
      terminaux et un ordre à deviner.
- [ ] 4.3 Un README de la démonstration : les prérequis mesurés en 0.1 — PHP 8.3,
      `--ignore-platform-req=ext-curl` — et **le fait que les deux endpoints ne sont pas des
      résidus de test**. La suite d'intégration en crée d'éphémères sur le même cluster et les
      supprime ; ceux-ci sont stables, nommés `demo-*`, et personne ne doit les « nettoyer ».

## 5. Le dire

- [ ] 5.1 Une page de documentation, ou une section, qui montre la démonstration plutôt que de la
      décrire — c'est la première fois que deux applications Durable se parlent.
- [ ] 5.2 Corriger au passage la citation de `sylius/config/packages/durable.yaml`, qui invoque
      DUR035 (`await()` et les conditions) là où la décision est DUR037.
