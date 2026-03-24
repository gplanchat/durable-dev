# Principes Karpathy pour les Agents

Règles comportementales pour réduire les erreurs de codage LLM courantes.
Basé sur [andrej-karpathy-skills](https://github.com/forrestchang/andrej-karpathy-skills).

> "Les modèles font des hypothèses incorrectes en votre nom et les suivent sans vérifier.
> Ils ne gèrent pas leur confusion, ne demandent pas de clarifications, ne présentent pas
> les compromis, ne contestent pas quand ils le devraient."
> — Andrej Karpathy

---

## Les 4 Principes

### 1. Réfléchir Avant de Coder

**Ne pas supposer. Ne pas cacher la confusion. Exposer les compromis.**

Avant d'implémenter :
- **Énoncer les hypothèses explicitement**. Si incertain, demander.
- **Si plusieurs interprétations existent**, les présenter — ne pas choisir silencieusement.
- **Si une approche plus simple existe**, le dire. Contester si justifié.
- **Si quelque chose n'est pas clair**, s'arrêter. Nommer ce qui est confus. Demander.

### 2. Simplicité d'Abord

**Code minimum qui résout le problème. Rien de spéculatif.**

- ❌ Pas de fonctionnalités au-delà de ce qui a été demandé
- ❌ Pas d'abstractions pour du code à usage unique
- ❌ Pas de "flexibilité" ou "configurabilité" non demandée
- ❌ Pas de gestion d'erreurs pour des scénarios impossibles
- ✅ Si 200 lignes peuvent être 50, réécrire

**Le test** : "Un senior dirait-il que c'est trop compliqué ?" Si oui, simplifier.

### 3. Changements Chirurgicaux

**Ne toucher que ce qui est nécessaire. Ne nettoyer que son propre désordre.**

Lors de l'édition de code existant :
- ❌ Ne pas "améliorer" le code adjacent, les commentaires ou le formatage
- ❌ Ne pas refactoriser ce qui n'est pas cassé
- ✅ Correspondre au style existant, même si vous feriez différemment
- ✅ Si vous remarquez du code mort non lié, le mentionner — ne pas le supprimer

Quand vos changements créent des orphelins :
- ✅ Supprimer imports/variables/fonctions que VOS changements ont rendus inutilisés
- ❌ Ne pas supprimer le code mort préexistant sauf demande explicite

**Le test** : Chaque ligne modifiée doit être traçable directement à la demande de l'utilisateur.

### 4. Exécution Orientée Objectifs

**Définir des critères de succès. Boucler jusqu'à vérification.**

Transformer les tâches en objectifs vérifiables :

| Au lieu de... | Transformer en... |
|---------------|-------------------|
| "Ajouter la validation" | "Écrire des tests pour les entrées invalides, puis les faire passer" |
| "Corriger le bug" | "Écrire un test qui le reproduit, puis le faire passer" |
| "Refactoriser X" | "S'assurer que les tests passent avant et après" |

Pour les tâches multi-étapes, énoncer un plan bref :

```
1. [Étape] → vérifier: [contrôle]
2. [Étape] → vérifier: [contrôle]
3. [Étape] → vérifier: [contrôle]
```

**Des critères de succès forts** permettent de boucler indépendamment.
**Des critères faibles** ("faire marcher") nécessitent des clarifications constantes.

---

## Comment savoir si ça fonctionne

Ces principes fonctionnent si vous observez :

| Indicateur | Signification |
|------------|---------------|
| Moins de changements inutiles dans les diffs | Seuls les changements demandés apparaissent |
| Moins de réécritures pour sur-complexité | Le code est simple dès la première fois |
| Questions de clarification AVANT l'implémentation | Pas après les erreurs |
| PR propres et minimales | Pas de refactoring "drive-by" |

---

## Application aux Agents Hive

Chaque agent DOIT appliquer ces principes :

| Agent | Application principale |
|-------|------------------------|
| `dev-backend-php` | Simplicité + Changements chirurgicaux |
| `dev-frontend-typescript` | Simplicité + Changements chirurgicaux |
| `architecte-*` | Réfléchir avant + Exposer les compromis |
| `revue-de-code` | Vérifier tous les principes |
| `debugger` | Exécution orientée objectifs (tests first) |

---

## Référence

- Source : https://github.com/forrestchang/andrej-karpathy-skills
- Auteur original : Andrej Karpathy
- Adaptation : Forrest Chang
