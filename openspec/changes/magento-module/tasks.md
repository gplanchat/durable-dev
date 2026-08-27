# Tasks

## 1. Build the instrument, and probe what the design guessed

A Tier 1 bootstrap has no unit test that proves it boots. The bench **is** the test harness, so it
comes first — and while it is being built it answers the four questions `design.md` records as
unmeasured.

- [x] 1.1 Track the `magento/` overlay the way `sylius/` is tracked. **The task was wrong about
      what that means.** "Sources yes, `vendor/` no" is not enough: with `vendor/` already excluded
      by the root `.gitignore`, `git add -An magento` still stages **10 178 files** — `dev/` alone
      brings 7 256 — all of them written by composer. `sylius/` is 220 files because a Sylius
      skeleton *is* project code; the Magento equivalent is eight files of overlay.
      So `magento/.gitignore` inverts the rule: ignore everything, re-allow the overlay by name.
      A file composer adds tomorrow stays out without anyone thinking about it, which an exclusion
      list cannot do. Verified: 8 files tracked, and three simulated distribution files change
      nothing. OST004's row corrected.
- [ ] 1.2 `composer install` reaches a working `bin/magento` on Mage-OS
      `product-community-edition:2.2.0`. The overlay has 61 vendor packages and no
      `vendor/magento/framework`, so this has never completed here. Record what the host actually
      needs — the extension list in `check-php-extensions.sh` is a claim, not a measurement.
- [ ] 1.3 **What a dying consumer leaves behind.** Kill `queue:consumers:start` mid-message and
      record what happens: redelivery, dead letter, or silence. This is the failure the whole
      integration exists to remove, and the design must not guess at its shape.
- [ ] 1.4 **Whether `LockManagerInterface` is shared across processes.** The design's only invariant
      rests on it. `Magento\Framework\Lock\Backend\Database` should be shared by construction — a
      `GET_LOCK` on the application database — but *should* is what probing is for. Measure it with
      two processes, not by reading the class.
- [ ] 1.5 How a Magento consumer behaves against a **long-poll** transport, which is what the
      Temporal bridge's workers are. A consumer runner that assumes short messages may starve or
      time out; if it does, the worker shape changes and task 4 changes with it.

## 2. The module boots

- [ ] 2.1 `src/DurableModule` with `registration.php` declaring `Gplanchat_Durable`, `etc/module.xml`,
      and a `composer.json` naming `gplanchat/durable-magento`. `bin/magento module:status` lists it.
- [ ] 2.2 The bench's path repository resolves — it points at `../src/DurableModule` today and finds
      nothing.
- [ ] 2.3 A configuration surface for the backend choice, refusing DBAL and Illuminate **at
      startup, by name**, the way the DBAL backend refuses Nexus. Not at the moment a workflow waits
      on a journal nobody writes.

## 3. Workflows and activities are discoverable

- [ ] 3.1 A registration mechanism for `#[Workflow]` and `#[Activity]` classes, since Magento's
      container has no tag autoconfiguration. Whether it is `di.xml` over an explicit list or a
      compiler-pass equivalent is task 1's answer to make, not this task's to assume.
- [ ] 3.2 A workflow class written once runs unmodified on the in-memory backend inside the bench.
      This is the first slice that proves the module is a *Durable* integration rather than a
      Magento module that happens to compile.

## 4. The queue carries the work

- [ ] 4.1 `communication.xml`, `queue_topology.xml`, `queue_publisher.xml`, `queue_consumer.xml` for
      workflow resume and activity dispatch — Magento's own queue, not a second one beside it.
- [ ] 4.2 The five roles `DurableBundle` covers with handlers: resume, activity run, signal
      delivery, update delivery, timer fire.
- [ ] 4.3 **One resume at a time.** The per-execution lock over `LockManagerInterface`, and a test
      that two consumers replaying one execution produce one journal rather than two. The test is
      the point; the lock without it is a claim.

## 5. Temporal, end to end

- [ ] 5.1 The workers as `bin/magento` commands, drained by what an operator already supervises.
- [ ] 5.2 The bench's `compose.yaml` Temporal stack runs a workflow from an order placed in the
      storefront to a completed execution visible in the Temporal UI.
- [ ] 5.3 The failure OST003 names: a consumer killed half way through an order resumes where it
      stopped, and does not re-charge the card. This is the acceptance test of the whole change.

## 6. Say it landed

- [ ] 6.1 An ADR for the decisions this change makes — the package name, the two-backend scope, the
      lock. A change that lands leaves an ADR behind.
- [ ] 6.2 The home page selector drops the `?` from `gplanchat/durable-magento`, and its state stops
      being `planned`. Through the canvas, not the generated file.
- [ ] 6.3 `documentation/user/packages/` and the guide's Backends page carry Magento, in both
      languages.
- [ ] 6.4 OST003 §Magento and OST004 §5 record what was actually built, and OST004's Magento row
      leaves the *"not built yet"* table.
