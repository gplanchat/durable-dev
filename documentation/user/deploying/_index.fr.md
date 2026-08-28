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

### Ou déclarer un point de changement

Quand le changement tient dans une branche, dites-le dans le workflow et laissez chaque exécution
garder le comportement sur lequel elle a commencé :

```php
use Gplanchat\Durable\Versioning\ChangePoint;

$version = $this->environment->version('add-discount', ChangePoint::DEFAULT_VERSION, 1);

if (ChangePoint::DEFAULT_VERSION === $version) {
    $total = $this->await($this->billing->totalWithoutDiscount($cart));   // les exécutions déjà en vol
} else {
    $total = $this->await($this->billing->totalWithDiscount($cart));      // tout ce qui part à partir de maintenant
}
```

La réponse est **fixée la première fois qu'une exécution atteint ce point**, puis relue dans son
journal. Déployez ensuite ce que vous voulez : une exécution qui a dépassé le point garde son
comportement.

Trois choses à savoir avant de s'en servir :

- **L'identifiant de changement vit dans le journal.** Le renommer plus tard fait paraître toutes
  les exécutions en vol comme n'ayant jamais atteint le point. Choisissez un nom avec lequel vous
  pourrez vivre.
- **Une exécution passée par cet endroit avant que le point n'existe reçoit `DEFAULT_VERSION`** —
  elle a commencé sur l'ancien comportement, elle finira dessus. Rien n'est écrit pour elle : elle
  est reconnue, pas marquée.
- **La garde de divergence s'applique toujours partout ailleurs.** Déclarer un point de changement
  n'autorise pas un changement non déclaré trois lignes plus bas : celui-là arrête toujours
  l'exécution.

### Supprimer l'ancienne branche

La branche peut partir dès qu'aucune exécution vivante ne peut plus s'y résoudre. Sur le **backend
Temporal**, c'est le serveur qui répond, chaque marqueur étant accompagné d'un attribut de recherche
standard :

```
temporal workflow list --query 'TemporalChangeVersion = "add-discount-1"'
```

Une réponse vide signifie que plus personne n'est en version 1, et que la branche `DEFAULT_VERSION`
peut disparaître.

**Sur les backends In-Memory et DBAL, il n'y a pas d'attribut de recherche et donc pas de réponse
équivalente.** Y savoir qu'une branche est morte revient à connaître ses propres exécutions — en
pratique, garder la branche jusqu'à en être sûr, ou passer par le renommage de type ci-dessous, dont
la fenêtre d'écoulement est visible.

### Ou donner un nouveau nom à la nouvelle forme

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
