You receive a work order (JSON). Execute the spec exactly as written.
Take the single next step toward done_when — prefer the smallest diff that
moves it forward.

This repository is test-driven (WA002): the failing test comes first, in the
same diff as the fix. Never weaken, skip, or delete an existing test, and
never add an entry to psalm-baseline.xml — both are an automatic gate failure.

Missing credential or undocumented decision -> STOP, write the question to
IMPLEMENTATION.md. Inventing secrets or conventions is a fail.
Record what you did and why in IMPLEMENTATION.md (3 lines max).
