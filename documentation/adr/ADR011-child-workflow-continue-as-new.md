# ADR011 — Child workflows, continue-as-new et politique d'annulation parent

## Contexte

Le composant Durable s'inspire de Temporal pour les workflows enfants et continue-as-new. Ce document décrit les écarts acceptés et la surface publique.

## Décisions

### 1. childWorkflowStub — API typée

L'API `childWorkflowStub(WorkflowClass::class, ?ChildWorkflowOptions)` fournit un proxy typé pour lancer un workflow enfant. Chaque appel à la méthode `#[WorkflowMethod]` du contrat délègue à `executeChildWorkflow(workflowType, input, options)`.

Aligné sur `newChildWorkflowStub` Temporal (stub typé, options).

### 2. Continue-as-new — Écart corrélation run

**Temporal** : continue-as-new conserve le **Workflow Id** et crée un nouveau **Run Id** ; l'historique est chaîné via des métadonnées serveur.

**Durable** : `continueAsNew(workflowType, payload)` crée une **nouvelle exécution** (nouvel `executionId`). La corrélation « même workflow logique » n'est pas exposée au niveau identité — chaque run est un `executionId` distinct.

**Décision** : accepter cet écart pour l'instant. La chaîne logique peut être portée par le `payload` (ex. `workflowId` métier) si besoin. Une évolution future pourrait introduire un champ `correlationId` ou `workflowId` stable dans le journal.

### 3. Parent close policy

`ChildWorkflowOptions` expose déjà `ParentClosePolicy` (Terminate, Abandon, RequestCancel). La surface est complète pour le modèle actuel.

## Références

- [OST004](../ost/OST004-workflow-temporal-feature-parity.md)
- [Child Workflows — Temporal PHP](https://docs.temporal.io/develop/php/child-workflows)
- [Continue-As-New — Temporal PHP](https://docs.temporal.io/develop/php/continue-as-new)
