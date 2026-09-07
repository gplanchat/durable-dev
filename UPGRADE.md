# Upgrading

Every public break in this repository comes with its migration procedure: **Rector first, a script
when Rector cannot, and documentation in every case.** This file is the third half — it says what
moved, and what puts it right.

```bash
composer require --dev gplanchat/durable-rector
```

```php
// rector.php
return Rector\Config\RectorConfig::configure()
    ->withImportNames()   // without it the rewritten names arrive fully qualified, next to a stale `use`
    ->withSets([__DIR__ . '/vendor/gplanchat/durable-rector/config/sets/durable-upgrade.php']);
```

```bash
vendor/bin/rector process src
```

The set is **cumulative**: running it once catches up every version crossed at once. It contains
only what Rector can do without guessing; everything else is written by hand below.

## Unreleased

### Stubs refuse what PHP refuses

**Who is affected**: any application that calls an activity, Nexus operation or child workflow
contract through a stub. Nothing to write; calls that used to go through in silence now throw, and
that is the point.

The three stubs turn the arguments received by `__call` into a named payload. Three call mistakes
used to vanish there without a word, and travelled all the way into the journal — where they replay
identically, pass after pass, far from the offending call:

| The call                                        | Before                  | Now                      | What PHP does on an ordinary call                     |
|-------------------------------------------------|-------------------------|--------------------------|-------------------------------------------------------|
| unknown named argument                          | ignored                 | `BadMethodCallException` | `Error: Unknown named parameter`                      |
| required parameter not supplied                 | comes out `null`        | `BadMethodCallException` | `ArgumentCountError`                                  |
| parameter supplied positionally **and** by name | the positional one wins | `BadMethodCallException` | `Error: Named parameter overwrites previous argument` |

The type is `\BadMethodCallException` and not PHP's own because the call goes through `__call`: it
is the exception the SPL reserves for a method called wrongly, and it stays catchable.

**If one of these exceptions shows up in production**, it points at a call that was already wrong: a
child workflow started with a missing parameter went off with `null` and waited for a message that
never came. Rector can do nothing here — the fix is in your calling code, not in a mechanical shape.

These exceptions are deterministic: replayed identically on every redelivery, they burn through
Messenger's attempts down to the failure transport. Configure one.

### Eleven internal bundle services become private

**Who is affected**: an application that pulls one of these eleven ids out of the container with
`$container->get()`. Not one that receives them by autowiring, nor one that goes through their
interface.

The concrete implementations behind an alias and the projection decorators have no business being
container entry points: a public service escapes *inlining* and the removal of unused definitions,
and becomes a compatibility promise nobody meant to make.

| Now private                                                                                                                 | Ask for this instead                                 |
| --------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| `durable.event_store.dbal`, `durable.event_store.temporal`, `durable.event_store.inner`, `durable.event_store.*.projecting` | `Gplanchat\Durable\Store\EventStoreInterface`        |
| `durable.workflow_metadata_store.inner`, `durable.workflow_metadata_store.*.projecting`                                     | `Gplanchat\Durable\Store\WorkflowMetadataStore`      |
| `durable.run_catalog.dbal`, `durable.run_catalog.in_memory`, `durable.run_catalog.temporal`                                 | `Gplanchat\Durable\Port\WorkflowRunCatalogInterface` |

The three interfaces stay **public** and autowirable, and they point at the same instance: what
changes is the path to get there, not what you get. The rest of the bundle's public surface is
unchanged — the Temporal workers, the parent/child link store, the profiler collector and the engine
classes stay reachable by their id.

Rector can do nothing: rewriting a `$container->get('durable.event_store.dbal')` into an injection
requires knowing where the object is used, which no rule can guess. The table above is the
procedure.

### `WorkflowHistorySourceInterface` gagne `hasSideEffectForSlot()`

**Qui est concerné** : uniquement qui **implémente** `WorkflowHistorySourceInterface` — c'est-à-dire
qui écrit un backend. Une application qui appelle `sideEffect()` n'a rien à changer ; elle gagne le
correctif sans rien faire.

**Ce qui était cassé.** `findSideEffectForSlot()` rend `mixed` et signalait « rien d'enregistré » par
`null`. Une closure qui rend légitimement `null` était donc indistinguable d'un slot vide : elle
était **ré-exécutée à chaque passe de rejeu**, et le journal grossissait d'un `SideEffectRecorded`
par passe. C'est la garantie même que `sideEffect()` existe pour offrir. Les valeurs `false`, `0`,
`''` et `[]` n'étaient pas touchées — la comparaison était un `!==` strict.

**Ce qu'il faut écrire.** Une méthode qui répond *le slot existe-t-il*, sans regarder ce qu'il porte.
Rector ne peut rien ici : la réponse dépend de la façon dont votre backend range ses slots, et lui
en faire deviner une produirait un adaptateur qui compile et ment. Les deux implémentations livrées
donnent les deux formes attendues.

Sur un journal parcouru :

```php
public function hasSideEffectForSlot(int $slot): bool
{
    $index = 0;
    foreach ($this->eventStore->readStream($this->executionId) as $event) {
        if ($event instanceof SideEffectRecorded) {
            if ($index === $slot) {
                return true;
            }
            ++$index;
        }
    }

    return false;
}
```

Sur un tableau indexé par slot — et c'est `array_key_exists()`, jamais `isset()`, qui rouvrirait
exactement le trou que ce correctif ferme :

```php
public function hasSideEffectForSlot(int $slot): bool
{
    return \array_key_exists($slot, $this->sideEffects);
}
```

`findSideEffectForSlot()` ne change pas de signature et garde son comportement : elle rend la valeur,
et rend `null` aussi bien pour un slot absent que pour un slot portant `null`. C'est désormais écrit
dans son contrat, et c'est `hasSideEffectForSlot()` qui décide s'il faut exécuter la closure.


### `version()` cesse de basculer une exécution en vol

**Qui est concerné** : toute application qui appelle `version()`. Rien à écrire ; le comportement
change, en mieux, et il faut savoir en quoi.

`version()` décide de rendre l'ancien comportement quand l'exécution est encore en train de
rejouer. Ce signal se déduisait des quatre types de slot qui savent dire leur présence — activité,
minuteur, workflow enfant, opération Nexus — et laissait les effets de bord de côté, pour la raison
même que le correctif ci-dessus vient de lever : leur présence ne se lisait pas sans lire leur
valeur.

Conséquence : une exécution dont le travail restant devant elle n'était fait que d'effets de bord
était vue comme arrivée au bout de son historique. Elle prenait la branche **neuve** au milieu d'un
rejeu et y écrivait son marqueur de version — dans une histoire écrite avant que le point de
changement existe. `hasSideEffectForSlot()` étant désormais au port, ce cas rejoint les autres.

Une exécution qui a déjà écrit un marqueur de version garde le sien : `versionForChangeId()` est
consulté en premier, et rien de ce commit ne le touche.

### Le profileur ne s'enregistre plus hors debug

**Qui est concerné** : une application qui tirait `durable.execution_trace` du conteneur en
production, ou qui injectait `WorkflowExecutionObserverInterface` en s'attendant à la trace.

Le collecteur, sa trace, son écouteur de remise à zéro et son middleware Messenger n'étaient posés
sous aucune condition. L'observateur qu'ils installent est injecté dans `ExecutionRuntime`,
`ExecutionEngine` et `ActivityMessageProcessor` : il passait donc sur le chemin chaud de chaque
exécution en production, pour alimenter une page que personne n'y sert. Et sa trace n'était vidée
que par un écouteur `kernel.request`, que `messenger:consume` ne déclenche jamais — un worker
l'accumulait tant qu'il vivait.

Hors `kernel.debug`, `WorkflowExecutionObserverInterface` pointe désormais
`Gplanchat\Durable\Debug\NullWorkflowExecutionObserver`. Le contrat d'observation est intact ;
c'est son implémentation qui ne fait plus rien. En debug, rien ne change, sinon que la trace porte
un tag `kernel.reset` et se vide donc aussi entre deux messages d'un worker.

Une application qui veut observer les exécutions en production n'a pas à ressusciter le profileur :
elle implémente `WorkflowExecutionObserverInterface` et aliase l'interface sur son propre service —
ce que le profileur faisait, en moins cher et sans accumuler une timeline pour l'écran de personne.

## 0.1.0-alpha8

### The divergence guard compares the payload too

`WorkflowHistorySourceInterface` gains three methods — `activityPayloadForSlot()`,
`nexusOperationPayloadForSlot()` and `childWorkflowInputForSlot()`, all `?array`. The divergence
guard (DUR042) only compared the slot's **identity** — activity name, child type, Nexus triplet; it
now compares the payload as well, on all three. A replay that asks for the same call again with a
different payload raises a `WorkflowTaskFailure` instead of carrying on in silence.

**Why** — the name alone let half the problem through. The journal served the old result, the
freshly computed payload went in the bin, and the execution finished **successfully** having lied
about what it had asked for. Measured on an agent mock-up: nine payloads computed, three journalled,
six divergences swallowed without a word, test suite green.

**What it changes for existing code** — a workflow that is already deterministic sees nothing. A
workflow that built its payload from a clock, a random draw or a read outside the journal now fails
its replay task, naming the byte where the two fingerprints diverge. That is the defect that had to
be seen: those executions were already returning a wrong result.

**What stays out of the guard's reach, deliberately** — the comparison goes through the fingerprint
the journal can hold (JSON round trip, sorted keys). An object the journal retains nothing of — a
DTO with private properties, the house style — therefore does not make a faithful replay diverge. An
unencodable payload (a resource, `NAN`) disarms the guard rather than accusing what it cannot read.
Histories written before this change have nothing to compare and pass unchanged.

**What Rector cannot do** — nothing to rewrite in the calling code. Only third-party implementations
of `WorkflowHistorySourceInterface` have to add the three methods; returning `null` reproduces
exactly the previous behaviour, with no guard on the payload.

**Nexus** — the guard applies there on the Temporal bridge side only, and that is structural: the
journal backend refuses Nexus operations by construction (DUR036), and its `NexusOperationScheduled`
event carries only the call site. No field was added to any event: the three payloads were already
on the wire.


### Laravel refuses at boot a workflow whose parameter names diverge from the contract

`gplanchat/durable-laravel` used to register without checking. A workflow carrying
`#[FulfilsNexusOperation]` with a **required** parameter matching no parameter of the contract now
makes registration fail, naming both signatures — the same refusal `NexusHandlerPass` has always
produced on the Symfony side, and from the same class:
`Gplanchat\Durable\Nexus\Serving\NexusFulfilmentParameterNames`.

**Why** — a Nexus operation's payload is keyed **by name** at both ends. A parameter renamed on one
side only breaks nothing when written, raises nothing when run, and arrives as `null`: the workflow
starts, runs and returns a result computed on nothing. Registration is the last moment anyone looks.

**What Rector cannot do** — nothing to rename mechanically: the right name is the contract's, and
only the author knows which of the two sides carries the typo. The refusal message prints both
parameter lists, which is exactly the information Rector would need in order to choose.

**Who is affected** — no application whose Nexus operations work: the refusal only strikes
configurations that were already returning `null` in silence. If boot fails after the upgrade, the
fault was already there, without saying so.

### A workflow that fulfils a Nexus operation must carry its tag

`NexusHandlerPass` used to read the `#[FulfilsNexusOperation]` attributes by **scanning every
definition in the container** and calling `class_exists()` on each one. It now reads the
`durable.nexus_fulfilment` tag, which `DurableBundle::build()` sets from the attribute.

**Why** — the scan loaded every class in the container in order to read its attributes. A single one
extending an absent parent is enough — a half-installed development bundle, and
`Symfony\Bundle\MakerBundle\Maker\AbstractMaker` is the real case that showed it — for the loading
to raise a **fatal error** in a compiler pass that had nothing to do with it. The tag says exactly
what we are looking for, and it already existed for that.

**What Rector cannot do** — nothing to rename: the break is a configuration one.

⚠ **What you have to do, if and only if** one of your workflows carrying
`#[FulfilsNexusOperation]` is declared with `autoconfigure: false`, or built by hand as a
`Definition`. The old scan saw it anyway; the tag does not. The symptom is a refusal at boot, and it
names the operation for you:

```
durable.nexus_handler: operation "encaisser" of contract … is served by nobody
```

Two ways to put it right, depending on what you wanted:

```yaml
services:
    App\Workflow\Encaissement:
        autoconfigure: true          # the tag comes back on its own
```

```yaml
services:
    App\Workflow\Encaissement:
        tags:
            - name: durable.nexus_fulfilment
              contract: 'App\Contract\FacturationContract'
              operation: 'encaisser'
```

### The parameter names of a workflow that fulfils an operation are checked

A workflow carrying `#[FulfilsNexusOperation]` with a parameter **without a default value** matching
no parameter of the contract method now makes container compilation fail.

**Why** — it is the quietest failure mode Nexus has. The payload is keyed by name when written and
read back by name on arrival: a parameter matching nothing received `null`, and the workflow
started, ran and returned a result computed on nothing.

**What you have to do** — if the refusal fires, one of the two sides has a typo. The message gives
both signatures. A parameter the contract deliberately ignores passes if it has a default value:
absence is then a decision, not an oversight.


### Resume orchestration moves down from the bundle into the core

- `Gplanchat\Durable\Bundle\Handler\ResumeWorkflowHandler` → `Gplanchat\Durable\Handler\ResumeWorkflowHandler`
- `Gplanchat\Durable\Bundle\Handler\FireWorkflowTimersHandler` → `Gplanchat\Durable\Handler\FireWorkflowTimersHandler`
- `Gplanchat\Durable\Bundle\Support\AsyncChildWorkflowFailureProjector` → `Gplanchat\Durable\Workflow\AsyncChildWorkflowFailureProjector`

**Why** — this was not a host adapter. Out of **279 lines, 21 touched Symfony** (imports included),
and those 21 served two things only: a v7 id, which `ExecutionId::generate()` already makes in the
core, and "publishing the timer wake-up after the current unit of work". The second became the port
`Gplanchat\Durable\Port\WorkflowTimerDispatcher`, for which the bundle supplies the Messenger
implementation. Six of the selector's hosts do not go through the bundle: leaving it there would
have meant as many copies of the resume semantics, divergent at the first fix.

**What Rector does** — the three renames. **What you have to do** — nothing more, if you were using
these classes indirectly: the bundle still wires them, at the same service ids. The `cache:clear`
remains necessary, for the reason below.

⚠ **If you had your own implementation** of `WorkflowTimerDispatcher` before it existed — impossible,
it is brand new — nothing to do. But if you were injecting a `MessageBusInterface` into a decorator
of these handlers, the seventh argument of `ResumeWorkflowHandler` and the fourth of
`FireWorkflowTimersHandler` are now a `WorkflowTimerDispatcher`, not a bus.

### `TimerWakeDelayCalculator` moves down from the bundle into the core

`Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator` becomes
`Gplanchat\Durable\Timer\TimerWakeDelayCalculator`.

**Why, and why it matters more than the next move** — this class imported nothing from Symfony
(timer events and the event store port), and `InMemoryWorkflowRunner`, which **is** core, called it.
`gplanchat/durable` does not require `gplanchat/durable-bundle`: on any host that does not install
the bundle, a resume that had to jump to the next timer raised a **fatal class-not-found error**.
Under Symfony nothing showed, the bundle always being there.

Found by replaying on Magento an order killed during its reservation. A guard now holds it: no file
in `src/Durable` imports a host or a bridge.

**What Rector does** — the rename. **What it cannot do** — the same `cache:clear` as below, for the
same reason.

### `PayloadToContractMethodInvoker` moves down from the bundle into the core

`Gplanchat\Durable\Bundle\Activity\PayloadToContractMethodInvoker` becomes
`Gplanchat\Durable\Activity\PayloadToContractMethodInvoker`.

**Why** — the class adapts a payload (an array, keys = parameter names) onto the method of an
activity contract. It lived in the Symfony bundle package **without importing a single line of it**,
and the Magento integration needs it word for word: its container has none of Symfony's tags, but
once the contract is resolved the adaptation is the same. It joins `ActivityContractResolver`, which
feeds it and which was already in the core.

**What Rector does** — the rename, everywhere the name appears.

**⚠ What Rector cannot do, and what you have to do by hand** — clear the container cache:

```bash
bin/console cache:clear
```

The fully qualified name is written into the **compiled container**. Without that clear, a Symfony
application keeps asking for the old name after the update, and the failure arrives at the first
activity call — far from its cause, and with nothing pointing at the move. It is also why Composer
cannot warn you: it installs both packages without a word, and the old name simply disappears.

**If you were not using it directly**, you had nothing to do in your code: the class was only
referenced by the bundle's compiler pass. The cache clear, though, remains necessary.

### Every declaration attribute takes the `As` prefix

The repository carried two conventions. The core named its attributes without a prefix
(`#[Workflow]`, `#[Activity]`); the Symfony bundle had a single one, prefixed
(`#[AsDurableActivity]`); neither the Illuminate bridge nor the Magento module had any. Serving
Nexus operations meant adding some, and therefore choosing. `As*` wins, and it says what it says:
*this declaration registers an X*.

**Method** attributes follow the same rule, so that there is one to remember rather than a rule and
its exception.

| before              | after                 |
|---------------------|-----------------------|
| `#[Workflow]`       | `#[AsWorkflow]`       |
| `#[Activity]`       | `#[AsActivity]`       |
| `#[WorkflowMethod]` | `#[AsWorkflowMethod]` |
| `#[ActivityMethod]` | `#[AsActivityMethod]` |
| `#[QueryMethod]`    | `#[AsQueryMethod]`    |
| `#[SignalMethod]`   | `#[AsSignalMethod]`   |
| `#[UpdateMethod]`   | `#[AsUpdateMethod]`   |

**What Rector does** — the rename, everywhere the attribute appears. Nothing else moves: the
arguments, the targets and the meaning of each attribute are unchanged. The set is therefore
replayable without harm.

### `AsDurableActivity` moves down from the bundle into the core, under the name `AsActivityHandler`

`Gplanchat\Durable\Bundle\Attribute\AsDurableActivity` becomes
`Gplanchat\Durable\Attribute\AsActivityHandler`.

Two changes in one, and they justify each other. The move first: this attribute declared that a
class implements an activity contract, which no framework makes specific to itself. Leaving it on
the Symfony side would have forced the Illuminate bridge and the Magento module each to invent
another one to say the same thing. The name second: `AsActivityHandler` pairs it with
`AsNexusServiceHandler`, both declaring an implementation by its contract.

**⚠ What Rector cannot do, and what you have to do by hand** — clear the container cache:

```bash
bin/console cache:clear
```

It is the same trap as for `PayloadToContractMethodInvoker`, only worse: this attribute is **read by
a compiler pass**. The compiled container keeps the fully qualified name, and an application that
upgrades without clearing its cache keeps looking for an attribute that no longer exists — with
nothing pointing at the move.

**If you were not using `#[AsDurableActivity]`**, you have nothing to do; the cache clear is
nevertheless still recommended, since the other entry in this version requires it.

### `JournalExecutionIdResolver::MEMO_KEY_JOURNAL_BOOTSTRAP` is removed

The constant named a memo that a **journal-native bootstrap** would have set — a slab of code that
never reached `main`. Six integration tests described it, four of the five classes they called never
existed, and those tests were deleted with their finding recorded. The constant had outlived them:
nothing read it any more, and its docblock described `workflowType`, which was never its content.

**What Rector does** — nothing. There is no replacement name: this is not a rename but a removal,
and inventing a target would be worse than saying nothing.

**What you have to do** — almost certainly nothing. This constant was read by no code in the
repository. If you reference it, then you were talking to a memo Durable never wrote:
`MEMO_KEY_DURABLE_EXECUTION_ID`, for its part, stays and is indeed the one `WorkflowClient` sets at
start.
