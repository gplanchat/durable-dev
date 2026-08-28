## Why

DUR036 took the caller side of Nexus and said the handler side would be its own change:

> A handler needs a Nexus task worker, its own poll loop, its own dispatch and its own failure
> vocabulary — `PollNexusTaskQueue`, `RespondNexusTaskCompleted`, `RespondNexusTaskFailed`, none of
> which the caller path touches. Bundling them would double the surface and halve the review.

This is that change.

What has come to light since is that the gap is not ours. The official Temporal PHP SDK has
**neither** side: `PollNexusTaskQueue` and `RespondNexusTaskCompleted` exist there only as generated
gRPC stubs on `ServiceClient`, `src/Workflow` has no Nexus API at all, and `src/Worker` has only a
task-slot counter in `WorkerOptions`. Temporal's own documentation carries a Nexus section for Go,
Java, Python, TypeScript and .NET, and none for PHP.

So the caller side we shipped already made this component the only way a PHP workflow calls a Nexus
operation. Serving one is the other half, and no PHP implementation offers it today. The reason to
build it is not parity with the SDK — there is nothing to reach. It is that a PHP service currently
cannot participate in a Nexus topology as anything but a consumer, which makes it a leaf in every
architecture that uses one.

## What Changes

- A component SHALL be able to declare that it serves a Nexus operation, and the runtime SHALL
  route an incoming request for that operation to the declared handler.
- An operation SHALL be able to complete **synchronously**, returning a result to the caller, and
  **asynchronously**, by starting a workflow whose eventual result becomes the operation's — the
  two shapes Nexus defines.
- A handler that fails SHALL fail the caller's operation with a failure the caller can classify,
  using the same classification the caller side already implements rather than a second vocabulary.
- A caller that cancels SHALL reach the handler, and a handler SHALL be able to observe the
  cancellation rather than discovering it by writing a result nobody wants.
- Serving SHALL require the Temporal backend, and the backends that cannot route SHALL refuse to
  register a handler at startup — loudly, at the moment of the mistake, rather than at the moment
  a request never arrives.
- **BREAKING** no. Nothing already shipped changes shape; a component that declares no handler
  behaves exactly as it does today.

### Not in scope

- **Endpoint provisioning.** Creating and configuring the endpoint that routes to this service is
  an operator's job on the server, and the caller side already treats it that way.
- **A second failure vocabulary.** If the caller's classification does not fit what a handler needs
  to express, that is a finding to record, not a licence to invent one here.
- **Serving on the in-memory or DBAL backends.** DUR036 settled the asymmetry and it applies with
  more force here: a handler with no route is not a degraded handler, it is a lie.

## Capabilities

### Modified Capabilities

- `nexus-operations`: gains the handler side — declaring a served operation, the two completion
  shapes, cancellation reaching the handler, and the backend rule for serving.

## Impact

- **Domain** (`src/Durable`): a declaration surface for served operations, and a dispatch that is
  the mirror of the one the caller already has.
- **Temporal bridge**: a Nexus task worker — poll loop, dispatch, the two respond calls. This is
  the bulk of the work and it is new plumbing, not an extension of the workflow task worker.
- **Symfony bundle**: registering handlers, and a worker command for the new task queue.
- **Backends without a route**: refusal at registration.
- **Test suite**: the caller and the handler in the same integration test, against a real server —
  the only place a round trip can be observed end to end.
- **ADR**: a new DUR; DUR036's "separate change" becomes a forward pointer.
- **Dependencies**: none beyond what the caller side already requires.
