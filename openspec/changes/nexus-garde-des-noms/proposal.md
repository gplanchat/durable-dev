## Why

Nexus's quietest failure mode is a parameter name. The payload is keyed **by name** at both
ends — `NexusStub::argumentsToPayload()` writes it from the contract's signature,
`WorkflowDefinitionLoader::mapInputToArguments()` reads it back in the workflow that fulfils the
operation. A parameter renamed on one side only breaks nothing on write, throws nothing at run time,
and arrives as `null`: the workflow starts, runs, and returns a result computed on nothing.

The refusal exists. It lives in `NexusHandlerPass`, **as a private method**, so it only exists for
Symfony applications. `change/demo-nexus-laravel` just put a second serving host into demo
production — `gplanchat/durable-laravel`, which declares its handlers in
`config/durable.php` — and had to write the warning in five places to say that there, nobody
checked. Five warnings are what you write when you cannot fix it yet.

## What Changes

The check moves down to the core, in one class:
`Gplanchat\Durable\Nexus\Serving\NexusFulfilmentParameterNames`. Two reflections and a read of
`#[AsWorkflowMethod]` — none of it belonged to a framework.

- `NexusHandlerPass` calls it and loses its private copy. Its message does not change, and neither
  do its tests: they are the migration's safety net.
- `DeclaredNexusOperations` calls it when it registers a fulfilment, so **at registration** and
  not at the first task.
- The message prefix is supplied by the host — `durable.nexus_handler` for the Symfony tag,
  `durable.nexus.handlers` for the Laravel key: the reader must find what they have to fix, not
  the class that refuses.

An **optional** parameter passes, here as there: giving a default value to a parameter the
contract does not carry is a decision; a missing default is a disappointed expectation.

## Impact

- `src/Durable/`: one more class, with no dependency.
- `src/DurableBundle/`: a call replaces a private method. No behaviour change.
- `src/DurableLaravel/`: **a refusal that did not exist**. Documented in `UPGRADE.md` — no
  application whose Nexus operations work is affected, since the refusal only hits
  configurations that were already silently returning `null`.
- The five warnings from `change/demo-nexus-laravel` that announced the missing check are
  replaced by what they now describe.
