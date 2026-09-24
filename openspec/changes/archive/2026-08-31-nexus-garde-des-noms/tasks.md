# Tasks

## 1. The guard moves down to the core

- [x] 1.1 `NexusFulfilmentParameterNames::assertMatch()` in `src/Durable/Nexus/Serving/`. The body
      is that of `NexusHandlerPass::assertParameterNamesMatch()`, but for one parameter: **who
      refuses** is passed by the caller. The reader of an error message looks for what they have to
      fix — a service tag or a configuration key —, not the class that threw it.
- [x] 1.2 The pass delegates and loses its private method. Its message is unchanged, and
      `NexusHandlerPassTest` passes **untouched**: it is the extraction's safety net, not a
      formality. 12 tests, 29 assertions, green before and after.

## 2. The second serving host inherits it

- [x] 2.1 **RED first.** `NexusOnLaravelTest::testAWorkflowWhoseParameterNamesDoNotMatchTheContractIsRefused`
      fails before the fix — *"Failed asserting that exception of type LogicException is
      thrown"* —, which demonstrates that the hole existed. Three cases in total: the
      workflow that covers the operation, the one whose `$ammount` diverges, and the one that adds an
      optional parameter.
      The fixtures carry a contract in **a single piece** whose handler implements only
      one operation: `DeclaredNexusOperations` reads through `method_exists()`, not the hierarchy,
      and that is the path that must be exercised.
- [x] 2.2 `DeclaredNexusOperations` calls the guard where it registers a fulfilment — so at
      registration, when the application boots, and not at the first Nexus task, when a
      caller is already waiting for an answer.
- [x] 2.3 Green: the `laravel` suite now carries only the four environment errors of the workstation
      (`illuminate/cache` is not installed at the root, CI installs it through its matrix), and the
      full `unit` suite passes — 1,070 tests, 2,704 assertions, zero failures.

## 3. Saying it

- [x] 3.1 `UPGRADE.md`: the break, and why Rector can do nothing for it — the right name is the
      contract's, and only the author knows on which side the typo is. With the sentence that
      matters to an operator: **no application whose Nexus operations work is
      affected**, the refusal only hits what was already silently returning `null`.
- [x] 3.2 The **seven** places that announced the missing check are revised: the
      `livraison` contract, the `ExpedierWorkflow` workflow, the READMEs of the Laravel bench and of the
      package, `demo/README.md`, and both languages of the Nexus page. §0.3 of
      `change/demo-nexus-laravel` keeps its sentence — it was true when it was written — and
      now points to this change.
