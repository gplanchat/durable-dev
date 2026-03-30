# ADR018 — Interdiction des blocs `catch` muets

## Statut

Accepté

## Contexte

En PHP, un bloc `catch` peut **masquer une défaillance** : l’exception est interceptée mais ni journalisée, ni transformée en résultat métier explicite, ni propagée. Un `catch` vide, un `catch` qui ne contient qu’un commentaire générique, ou un `catch` qui « avale » l’exception sans action observable équivalente à une **perte de signal** pour l’exploitation, le support et l’analyse post-mortem.

Ce phénomène n’est pas couvert par ADR008 (erreurs **dans le modèle durable** : journal d’événements, retries d’activités) ni par la règle PHP-CS-Fixer `error_suppression` d’ADR002 (opérateur `@` PHP), alors qu’il s’agit d’un risque récurrent dans le code applicatif et les intégrations.

## Décision

### 1. Pattern interdit

Il est **interdit** d’écrire un bloc `catch` qui :

- est **vide** ; ou
- ne fait qu’**ignorer** l’exception sans **traitement observable** : pas de journal, pas de re-lancement, pas de conversion en valeur / erreur métier documentée, pas d’enregistrement dans un système de suivi.

Un commentaire du type « on ignore » ou « ne pas supprimer » **ne remplace pas** un traitement : il n’apparaît pas dans les logs d’exécution et n’aide pas au diagnostic.

### 2. Pratiques obligatoires pour éviter ce pattern

À la place, **au moins une** des approches suivantes doit s’appliquer explicitement dans le bloc ou immédiatement après :

1. **Journaliser puis relancer**  
   - Logger l’exception avec le contexte utile (`executionId`, identifiants métier non sensibles, nom d’opération) via **PSR-3** (`LoggerInterface`) ou le mécanisme de log du framework, puis **`throw $e`** ou envelopper dans une exception du domaine (`throw new MonErreurMetier('…', 0, $e)`).

2. **Journaliser et convertir**  
   - Transformer l’échec en **résultat typé** (ex. `Result::failure()`, valeur `null` + log d’avertissement **avec la cause** attachée en contexte) lorsque le contrat d’API l’exige explicitement — le **refus silencieux** sans log reste interdit.

3. **Ne pas intercepter**  
   - Si aucun traitement local n’est nécessaire, **laisser remonter** l’exception (pas de `catch` superflu).

4. **Cas métier documenté avec minimum observable**  
   - Si une exception **doit** être ignorée (ex. concurrence attendue, idempotence, « déjà traité »), le code doit au minimum :  
     - **logger en niveau adapté** (`debug` ou `info` avec justification dans le message ou le contexte) **ou**  
     - **incrémenter une métrique / compteur** exposé à l’observabilité ;  
     - et un **commentaire de revue** ou une **référence ADR / ticket** dans le code si la raison n’est pas évidente.

5. **Handler global ou middleware**  
   - Pour du bruit répétitif, préférer un **filtre centralisé** (listener Symfony, middleware, `ErrorHandler`) qui log/trace une fois, plutôt que des `catch` vides dispersés.

### 3. Revue et outillage

- Les **revues de code** doivent rejeter tout nouveau `catch` muet.
- Où c’est disponible, les outils d’analyse statique ou les règles d’équipe peuvent compléter (détection de `catch` vides) ; l’ADR reste la **norme** même si l’outil ne couvre pas tous les cas.

## Conséquences

- Le code existant peut contenir des exceptions historiques ; les **nouveaux** changements doivent se conformer à cette ADR.
- Légère verbosité accrue (logs ou rethrows) en échange d’une **traçabilité** nettement meilleure.
- Cette règle est **orthogonale** à ADR008 : un workflow qui « rattrape » une erreur d’activité avec un `catch` autour de `await()` reste valide **si** le comportement métier et le journal durable sont cohérents ; en revanche, un `catch` vide côté **application** reste interdit.

## Références

- [ADR002 — Coding standards](ADR002-coding-standards.md) (dont `error_suppression` pour `@`)
- [ADR008 — Error handling and retries](ADR008-error-handling-retries.md) (modèle d’erreurs durable, activités, journal)
- [PSR-3 — Logger Interface](https://www.php-fig.org/psr/psr-3/)
