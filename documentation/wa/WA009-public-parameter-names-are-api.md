# WA009 - Public parameter names are API

## Status

Accepted - 30 September 2026 (the user's decision of the same day).

## Context

#698, #699 and #705 changed the documentation to call the public surface with named arguments:
`deadline:` on `await()`, `minSupported:` and `maxSupported:` on `version()`, `timerSummary:`,
the Nexus `payload:` and `timeouts:`, and `until(from:)`.

PHP resolves a named argument by the parameter's name. Once a page shows `await(deadline: ...)`,
a caller can write it, and renaming `$deadline` breaks that caller with an `Error` at run time,
although the type and the position did not move.

## Agreement

**The name of a parameter of a public method is public API**, on the same footing as the method
name and the parameter's type and position.

### What is public

- Every public and protected method, constructor and function of a class, interface, trait or enum
  in a `src/` package that is not marked `@internal`. Protected counts for anything not `final`,
  because a subclass or a caller through a subclass sees it.
- Every public parameter name that a page under `documentation/user/` or `hugo-docs/` shows as a
  named argument. That one is public even on a class marked `@internal`: the page made it a promise.

### What is not

- A class, method or parameter marked `@internal`, unless a user page shows it (above).
- Private methods, and protected methods of a `final` class.
- `src/Bridge/Temporal/Api/` and `src/Bridge/Temporal/Generated/`, which are generated: their names
  follow protobuf.

## Consequence

Renaming a public parameter is a BC break and follows the same procedure as any other:

1. **An `UPGRADE.md` entry**, added at the **end** of "## Unreleased", naming the method, the old
   and the new parameter name, and who is affected (a caller that passes it by name; an
   implementer or subclass that overrides the method is affected only by a static-analysis warning).
2. **A migration procedure. Rector first.** Rector 2.6.4, the version in `vendor/rector`, has
   **no rule for renaming a named argument**: `RenameMethodRector`, `RenameParamToMatchTypeRector`
   and `RenameAttributeRector` do not touch the `name` of an `Arg`. So a rename needs either a
   small rule in `src/DurableRector/Rector/`, added to the `durable-upgrade.php` set, or, when a
   rule would have to guess, a documented manual step: the `grep` that finds the call sites
   (`git grep -n 'oldName:'`) and the replacement.
3. The rename is otherwise avoided: a parameter is renamed only when the old name is wrong, not
   because the new one is nicer.

## Related

- **WA002** - the failing test comes first: a rename lands with a test that calls the new name.
- **DUR039** - what the workflow authoring surface is.
