## Why

`demo-nexus-deux-applications` put two sample apps side by side and had them call each other in
both directions. Its proof is measured, and it holds for what it shows: **Symfony and Sylius**, two
applications on the same framework, two namespaces, each one both caller and server.

What that proof does not say is what Nexus asks of the **host**. The two sample apps share the
Symfony container, the compiler pass that registers the handlers, and the Messenger transport that
runs the workers. A reader entitled to conclude that Nexus is a feature of the Symfony bundle
would have misread nothing.

The repository has a third sample app, and it has **none** of that: `magento/` wires its services
in `di.xml`, runs its workers through a `bin/magento durable:worker` command, and reads its DSN from
`app/etc/env.php`. If it calls an operation served by the other two without a single line added to
the core, the demonstration says something that two Symfony applications could not
say.

And a reservation is waiting to be lifted: `magento-module` §3bis.9 explicitly excludes the Nexus
case from `EveryCaseWorkflow` — *"Nexus requires two applications, which belong to
`change/demo-nexus-deux-applications`"*. They exist now.

## What Changes

The Magento sample app joins the demonstration, on a third namespace `demo-magento`, **as a
caller only**:

- it calls `stock/reserver`, served **synchronously** by the Sylius shop;
- it calls `facturation/verifier` then `facturation/encaisser`, the latter fulfilled by a
  **workflow** of the Symfony business app.

A single workflow, in the test-bench module `Gplanchat_DurableProbe`, calls both services — hence
both response shapes and both existing endpoints, from a host that is not Symfony.

### Why caller only

Serving requires the host to register handlers and poll a Nexus queue: on the Symfony side that
is `NexusHandlerPass` and a Messenger transport, and Magento has neither. Calling requires
**nothing** — `WorkflowEnvironment::nexusStub()` reads the contract by reflection, and
`WorkflowTaskRunner` is already the worker that all three hosts share.

That imbalance is precisely the demonstration: the calling side is free everywhere, the serving
side is wired once per host. Making Magento serve would require work in the module — it will get
its own change, and that change will start where this one stops.

### What the Magento bench must gain

The shared contract, a workflow, a command to start it, and a DSN pointing at the demonstration's
cluster. Its own cluster (`temporalio/auto-setup:1.25.2` in its `compose.yaml`) answers
`Nexus APIs are disabled` — this is already written in `demo/README.md`, and it is the reason why
the demonstration has its own `temporal server start-dev`.

## Impact

- `magento/`: the contract as a path repository, a workflow and a command in `Gplanchat_DurableProbe`.
- `bin/demo-nexus`: a third namespace, and **no additional endpoint** — an endpoint designates who
  serves, and Magento does not serve.
- `demo/lancer.sh` and `demo/README.md`: one more process, and the count redone.
- `documentation/user/nexus/`: the section "Two applications, for real" becomes three, in both
  languages.
- No change to the published packages: neither the core, nor the Temporal bridge, nor
  `durable-magento`. If one of them has to change, the assumption above is wrong, and §0 will say
  so before anything is written.
