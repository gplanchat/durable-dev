---
title: Modifier un workflow en cours
weight: 28
---

# Modifier un workflow déjà en cours d'exécution

Un workflow (la classe PHP qui décrit les étapes d'une exécution ; voir le
[glossaire](../glossary/)) qui tourne pendant des semaines survit au déploiement qui l'a lancé. Ce
guide montre ce qui se passe quand vous déployez un changement alors que des exécutions
(déroulements durables d'un workflow) sont encore en vol, et comment y faire face.

## Comment une exécution en cours rencontre le nouveau code {#pourquoi-une-exécution-en-cours-nest-pas-simplement-du-vieux-code}

Un workflow ne reprend pas là où il s'est arrêté : il **rejoue depuis le début** à chaque tâche, et
chaque étape qu'il planifie est appariée au journal (l'historique enregistré des étapes de
l'exécution et de leurs résultats) **par position**. L'étape 3, c'est la troisième activité (un
appel avec effet de bord) que ce workflow a planifiée, pas le troisième appel à cette activité-là.

Insérer un appel devant un autre décale donc tout ce qui suit, et la position 3 dans le code ne
correspond plus à la position 3 dans le journal.

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

## L'erreur de divergence {#ce-que-vous-verrez}

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

### Revenir à la version précédente {#revenir-en-arrière-et-lexécution-se-termine}

L'échec signifie que le déploiement ne convient pas aux exécutions sur lesquelles il est tombé.
Remettez la version précédente, et le réessai suivant rejoue proprement, car seule la tâche a
échoué : l'exécution repart exactement là où elle en était, et ne perd que le temps écoulé entre
les deux déploiements.

### Ou déclarer un point de changement

Quand le changement tient dans une branche, déclarez un point de changement (une bifurcation nommée
dans le code du workflow ; voir le [glossaire](../glossary/)) et laissez chaque exécution garder le
comportement sur lequel elle a commencé :

```php
use Gplanchat\Durable\Versioning\ChangePoint;

$version = $this->environment->version('add-discount', minSupported: ChangePoint::DEFAULT_VERSION, maxSupported: 1);

if (ChangePoint::DEFAULT_VERSION === $version) {
    $total = $this->await($this->billing->totalWithoutDiscount($cart));   // les exécutions déjà en vol
} else {
    $total = $this->await($this->billing->totalWithDiscount($cart));      // tout ce qui part à partir de maintenant
}
```

La réponse est **fixée la première fois qu'une exécution atteint ce point**, puis relue dans son
journal. Vous pouvez ensuite déployer d'autres changements : une exécution qui a dépassé le point
garde son comportement.

Avant de vous en servir, notez les points suivants :

- **L'identifiant de changement vit dans le journal.** Le renommer plus tard fait paraître toutes
  les exécutions en vol comme n'ayant jamais atteint le point. Choisissez un nom que vous garderez.
- **Une exécution passée par cet endroit avant que le point n'existe reçoit `DEFAULT_VERSION`.**
  Elle a commencé sur l'ancien comportement, elle finira dessus. Rien n'est écrit pour elle : elle
  est reconnue, pas marquée.
- **La reconnaître demande du travail enregistré après le point.** « Passée par cet endroit » se déduit
  de l'activité, du timer, de l'enfant, de l'opération Nexus ou du side effect que le journal
  contient au-delà du point. Une exécution qui l'a passé et n'attend plus qu'une condition (un
  signal ou une mise à jour) n'en a pas : un point inséré avant cette attente lui donne la nouvelle
  version. Placez le point après l'attente, ou avant du travail enregistré.
- **La garde de divergence s'applique toujours partout ailleurs.** Un changement non déclaré trois
  lignes sous un point de changement déclaré arrête toujours l'exécution.

### Supprimer l'ancienne branche

Vous pouvez supprimer la branche dès qu'aucune exécution vivante ne peut plus s'y résoudre. Sur le
**backend Temporal**, c'est le serveur qui répond à cette question, car chaque marqueur est
accompagné d'un attribut de recherche standard :

```
temporal workflow list --query 'TemporalChangeVersion = "add-discount-1"'
```

Une réponse vide signifie que plus personne n'est en version 1, et que la branche `DEFAULT_VERSION`
peut disparaître.

Quand une branche part, relevez le minimum avec elle : `version('add-discount', 1, 1)` une fois la
branche `DEFAULT_VERSION` supprimée. Une exécution encore sur une version hors de cette plage
échoue alors avec une `WorkflowTaskFailure` qui nomme le point de changement, sa version et la
plage, au lieu de prendre en silence la branche restante. Sur Temporal, la tâche échoue et
l'exécution attend un code qui la supporte. Sur les backends à journal, l'exécution se termine,
comme pour une divergence (voir plus bas).

**Sur les backends à journal (en mémoire, DBAL et Illuminate), il n'y a pas d'attribut de recherche
et donc pas de réponse équivalente.** Vous ne savez alors qu'une branche est morte qu'en
connaissant vos propres exécutions. En pratique, gardez la branche jusqu'à en être sûr, ou passez
par le renommage de type ci-dessous, dont la fenêtre d'écoulement est visible.

### Ou enregistrer le workflow sous un nouveau nom de type {#ou-donner-un-nouveau-nom-à-la-nouvelle-forme}

Quand vous ne pouvez pas attendre que les exécutions s'écoulent, enregistrez le workflow modifié
sous un **nouveau nom de type** et gardez l'ancienne classe enregistrée jusqu'à ce que les anciennes
exécutions se terminent :

```php
#[AsWorkflow('checkout')]      // à garder, jusqu'à la fin de la dernière ancienne exécution
final class CheckoutWorkflow { … }

#[AsWorkflow('checkout-v2')]   // les nouveaux démarrages passent ici
final class CheckoutV2Workflow { … }
```

Une exécution résout son gestionnaire par le type enregistré à son démarrage : celles qui sont déjà
en vol ne voient donc jamais la nouvelle classe. Les nouvelles démarrent sur `checkout-v2`.

Cela coûte deux classes et une fenêtre d'écoulement. Prenez cette voie quand le changement est **trop
grand pour s'exprimer en branche**, par exemple un autre ensemble d'activités et une forme
entièrement différente, là où un point de changement ferait seulement porter deux workflows à un
seul.

## Ce qui n'est pas vérifié

**Les minuteurs.** Un minuteur enregistre une date d'échéance absolue, pas le délai qui l'a produite,
et son libellé est facultatif : rien dans le journal n'identifie *quel* minuteur occupe une position.
Ne changer que des durées de minuteur se rejoue donc sans être signalé.

Un décalage n'échappe au contrôle que s'il touche **uniquement** des minuteurs. Dès qu'une activité
bouge avec lui, le contrôle du nom de l'activité le détecte.

## Sur les backends sans tâches de workflow

Les backends à journal (en mémoire, DBAL et Illuminate) n'ont pas de notion de *tâche* de workflow,
il n'y a donc rien à faire échouer puis à réessayer. Là, une divergence met fin à l'exécution au
lieu de résoudre en silence la mauvaise valeur enregistrée, et revenir en arrière ne ramène pas
l'exécution.

---

Deux décisions couvrent cette page :
[DUR042](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR042-replay-divergence-guard.md)
pour la raison pour laquelle le contrôle ne s'appuie que sur ce que le journal enregistre déjà, et
[DUR044](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR044-declared-change-points.md)
pour les points de changement, y compris pourquoi une exécution antérieure au point est reconnue
plutôt que marquée, et pourquoi l'attribut de recherche fait partie de la primitive au lieu d'être
un ajout.
