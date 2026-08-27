---
title: Modifier un workflow en cours
weight: 28
---

# Modifier un workflow déjà en cours d'exécution

Un workflow qui tourne pendant des semaines survit au déploiement qui l'a lancé. Tôt ou tard, vous
déploierez un changement alors que des exécutions sont encore en vol, et ce qui se passe alors vaut
mieux d'être su avant que d'être découvert après.

## Pourquoi une exécution en cours n'est pas simplement du vieux code

Un workflow ne reprend pas là où il s'est arrêté : il **rejoue depuis le début** à chaque tâche, et
chaque étape qu'il planifie est appariée au journal **par position**. L'étape 3, c'est la troisième
activité que ce workflow a planifiée — pas le troisième appel à cette activité-là.

Insérer un appel devant un autre décale donc tout ce qui suit. La position 3 dans le code ne veut
plus dire ce que veut dire la position 3 dans le journal.

```php
// Le code qui a démarré l'exécution
$this->await($this->activities->chargeCard($order));   // position 0
$this->await($this->activities->shipOrder($order));    // position 1

// Le code que vous venez de déployer
$this->await($this->activities->reserveStock($order)); // position 0  ← inséré
$this->await($this->activities->chargeCard($order));   // position 1
$this->await($this->activities->shipOrder($order));    // position 2
```

L'exécution en vol a enregistré `chargeCard` en position 0. Le nouveau code y demande
`reserveStock`.

## Ce que vous verrez

L'exécution s'arrête sur cette tâche, et l'échec nomme les deux côtés :

```
Replay divergence at activity slot 0 of execution "order-8f21c3":
history recorded "chargeCard", code scheduled "reserveStock".
This history was written by a different version of the workflow.
```

Sur le backend Temporal, c'est la **tâche de workflow** qui échoue, pas l'exécution. Celle-ci reste
vivante, son historique est intact, et le serveur réessaie la tâche. Dans l'interface Temporal, cela
apparaît comme un échec de tâche de workflow, l'exécution restant en `Running`.

Chaque étape qui porte une identité est vérifiée ainsi : les activités par leur nom, les opérations
Nexus par leur triplet point d'entrée / service / opération, les workflows enfants par leur type.

## Que faire

### Revenir en arrière, et l'exécution se termine

L'échec vous dit que le déploiement ne convient pas aux exécutions sur lesquelles il est tombé.
Remettez la version précédente et le réessai suivant rejouera proprement — l'exécution repart
exactement là où elle en était, n'ayant rien perdu que le temps écoulé entre les deux déploiements.

C'est toute la raison pour laquelle c'est la tâche qui échoue, et non l'exécution.

### Ou donner un nouveau nom à la nouvelle forme

Quand vous ne pouvez pas attendre que les exécutions s'écoulent, enregistrez le workflow modifié
sous un **nouveau nom de type** et gardez l'ancienne classe enregistrée jusqu'à ce que les anciennes
exécutions se terminent :

```php
#[Workflow('checkout')]      // à garder, jusqu'à la fin de la dernière ancienne exécution
final class CheckoutWorkflow { … }

#[Workflow('checkout-v2')]   // les nouveaux démarrages passent ici
final class CheckoutV2Workflow { … }
```

Une exécution résout son gestionnaire par le type enregistré à son démarrage : celles qui sont déjà
en vol ne voient donc jamais la nouvelle classe. Les nouvelles démarrent sur `checkout-v2`.

Cela coûte deux classes et une fenêtre d'écoulement, et c'est aujourd'hui la seule façon de modifier
un workflow sans attendre. Une primitive de versionnage par point de changement est un chantier à
part.

## Ce qui n'est pas vérifié

**Les minuteurs.** Un minuteur enregistre une date d'échéance absolue, pas le délai qui l'a produite,
et son libellé est facultatif — rien dans le journal n'identifie *quel* minuteur occupe une position.
Ne changer que des durées de minuteur se rejoue donc sans être signalé.

L'angle mort est plus étroit qu'il n'y paraît : un décalage n'échappe au contrôle que s'il touche
**uniquement** des minuteurs. Dès qu'une activité bouge avec lui, le nom de l'activité l'attrape.

## Sur les backends sans tâches de workflow

Les backends en mémoire et DBAL n'ont pas de notion de *tâche* de workflow — il n'y a rien à faire
échouer puis à réessayer. Là, une divergence met fin à l'exécution. Cela reste bien préférable à
l'autre solution, qui serait de résoudre en silence la mauvaise valeur enregistrée, mais revenir en
arrière ne ramènera pas l'exécution.

---

La décision qui sous-tend tout ceci, y compris la raison pour laquelle le contrôle ne s'appuie que
sur ce que le journal enregistre déjà, est
[DUR042](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR042-replay-divergence-guard.md).
