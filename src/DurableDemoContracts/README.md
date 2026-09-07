# The demonstration's Nexus contracts

The contracts the repository's mockups share: `sylius/` serves `stock` in the `demo-shop` namespace,
`symfony/` serves `billing` in `demo-business`, `laravel/` serves `delivery` in `demo-laravel`, and
each of them calls the others. A Nexus contract is written once and read on both sides of the
boundary, so it needs a place that belongs to neither.

## Deliberately unpublished

This package has no line in `bin/splitsh-publish.sh`, no satellite repository, no token. That is a
decision, not an oversight.

Publishing costs an entry in the splits checklist, a repository to create, a PAT to widen, and above
all a compatibility promise. Its only consumers are in this repository, versioned with it, updated in
the same commit. None of that would buy anything.

The mockups therefore declare it as a `path` repository, as they already do for the core packages,
and the root maps it in its `autoload` without listing it in its `require`.

If an outside project ever needs it, then it had stopped being a demonstration contract. The order is
then the one of every other satellite: create the repository, widen the token's scope, and only then
add the line to `bin/splitsh-publish.sh`; the reverse gives a 404 and then a 403.

## The payload travels as it is written

The operations take and return only scalars and arrays. That is not typing timidity: a Nexus payload
is plain JSON, decoded into an associative array on the other side. An object passed as a parameter
would arrive as an array, and the handler would throw a `TypeError`, which is also what lets a
handler written in Go or in TypeScript read the same fields.

## The parameter names are the interface

`NexusStub::argumentsToPayload()` keys the payload **by parameter name**; on the served side,
`WorkflowDefinitionLoader::mapInputToArguments()` reads it back by name. Renaming a parameter on one
side breaks nothing visible: the workflow receives `null`, in silence.

A parameter in these files is therefore renamed on both sides, or not at all.
