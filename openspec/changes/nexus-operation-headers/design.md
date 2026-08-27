## Context

`ScheduleNexusOperationCommandAttributes` carries a `map<string, string>` of Nexus headers. Nothing
in the domain builds one, and the bridge does not write one. Before any of that is built, the rules
have to come from the server — the discipline this component has followed for `TaskQueue`,
`CronSchedule`, `NexusEndpoint` and the operation bounds.

## What was probed, and what was assumed

**Header rules, probed against Temporal 1.31.2 (tasks 1.1 and 1.2).** The command is assembled by
hand, since the bridge does not send headers yet, and the verdict is read back from
`NEXUS_OPERATION_SCHEDULED`. Pinned by `NexusHeaderRulesTest`.

| Case | Verdict |
|---|---|
| `x-correlation: abc-123` | accepted, returned **verbatim** |
| empty value | accepted verbatim |
| **empty key** | accepted verbatim |
| leading/trailing space in the value | accepted verbatim |
| newline inside the value | accepted verbatim |
| space inside the key | accepted verbatim |
| 1000-character value | accepted verbatim |
| two headers | accepted verbatim |
| `X-Correlation` | accepted, key **silently lowercased** to `x-correlation` |
| `X-TOUT-MAJ` | accepted, key **silently lowercased** |
| `X-Choc` **and** `x-choc` together | accepted, **one header disappears** — only `x-choc` survives |

**The server is permissive on everything except case.** It keeps an empty key, an empty value, inner
whitespace, a newline — none of that justifies a value object being stricter, and refusing any of it
would reject headers the server happily carries.

**The one rewrite is the key's case, and its consequence is a silent loss.** Two headers whose keys
differ only by case collide: two go in, one comes out, with no error and nothing in history to say
which was dropped. That is the failure mode this codebase names in `TaskQueue` — accepted, then
silently useless — and it is the only thing §2.1 has to prevent.

## Decisions

### The value object lowercases the key, and refuses a collision

Two rules, both grounded in the table above and neither invented:

- **Lowercase at construction.** What the caller holds must be what the server keeps, otherwise
  reading back one's own header lies. This is a coercion, not a refusal: `X-Correlation` is a
  perfectly valid header, it simply *is* `x-correlation`.
- **Refuse two keys that collide once lowercased.** Here the caller is asking for something the
  server cannot do, and it will not say so. A duplicate key is either a typo or a
  misunderstanding — both worth an error at the call site rather than a header that vanishes.

Nothing else is validated. An empty key is accepted because the server accepts it; being stricter
than the server buys nothing here, since a malformed header does not fail silently — only the
collision does.

### Where the coercion lives

In the value object, not in the bridge. The bridge writing lowercase keys would leave the domain
holding a value the server never saw, and the round-trip test would be comparing the caller's
intent against the server's memory of something else.
