# Opportunités futures

OST001-future-opportunities
===

Introduction
---

Ce **Opportunity Solution Tree** explore les opportunités d'évolution du projet Durable. Ces hypothèses sont à valider avant tout développement.

Opportunités identifiées
---

### 1. Temporal comme driver optionnel

**Opportunité** : Permettre l'utilisation de Temporal.io comme backend pour les workflows, tout en évitant RoadRunner.

**Décision** : Temporal reste une **opportunité future** documentée dans cet OST. Aucune implémentation n'est prévue à court terme. L'interface `WorkflowBackendInterface` permet une éventuelle évolution sans modifier le noyau.

**Solutions envisagées** (à valider) :
- Interface `WorkflowBackendInterface` déjà en place — adapter local (EventStore + Messenger)
- Adapter Temporal (futur) : client gRPC, workers PHP standards (sans RoadRunner)
- Contrainte : le SDK Temporal PHP cible RoadRunner pour les workflows ; une intégration sans RR nécessiterait une approche hybride ou custom

**Hypothèses à valider** :
- Le SDK Temporal PHP peut-il être utilisé sans RoadRunner pour les activités uniquement ?
- Un mode hybride (workflows locaux, activités Temporal) est-il pertinent ?

### 2. Multi-transport et re-dispatch workflow

**Opportunité** : Support du mode distribué complet où workflow et activités s'exécutent dans des process séparés.

**Solutions envisagées** :
- Workflow = job Messenger qui se re-dispatch après chaque activité (modèle Durable Workflow)
- Consommateur dédié `durable:workflow:consume` qui réveille les workflows à la complétion d'activités

**Hypothèses** :
- La complexité du re-dispatch est-elle acceptable pour le gain en scalabilité ?
- Quel impact sur la latence des workflows courts ?

### 3. Timers avancés

**Opportunité** : Timers basés sur une clock réelle (cron, date absolue) plutôt que des délais relatifs.

**Solutions envisagées** :
- Extension de `ExecutionContext::delay()` avec des signatures `delayUntil(DateTimeInterface)`
- Intégration avec une table de timers persistée (Dbal) pour la reprise post-crash

**Hypothèses** :
- Les timers absolus sont-ils un cas d'usage prioritaire ?
- Comment gérer le replay des timers sans exécution multiple ?

### 4. Observabilité

**Opportunité** : Métriques, traces et logs structurés pour le suivi des workflows.

**Solutions envisagées** :
- Intégration OpenTelemetry
- Events de domaine exposés pour le tracing
- Métriques (durée d'exécution, taux d'échec, file d'attente)

Arbre de décision
---

```
Objectif : Évolutions du composant Durable
├── Temporal driver ?
│   ├── Oui → Interface WorkflowBackend + adapter Temporal (sans RR)
│   └── Non → Garder implémentation locale uniquement
├── Mode distribué complet ?
│   ├── Oui → Re-dispatch workflow + ADR dédiée
│   └── Non → Mode inline actuel suffisant
├── Timers avancés ?
│   ├── Oui → Extension delay + persistance
│   └── Non → delay(seconds) actuel suffisant
└── Observabilité ?
    ├── Oui → OpenTelemetry / métriques
    └── Non → Logging basique
```

Références
---

- [PRD001 - État actuel](../prd/PRD001-current-component-state.md)
- [ADR005 - Messenger](../adr/ADR005-messenger-integration.md)
- [OST → ADR] Les décisions techniques issues de cet OST donneront lieu à de nouveaux ADR
