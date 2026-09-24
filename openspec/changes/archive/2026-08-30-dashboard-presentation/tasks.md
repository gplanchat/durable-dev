## 1. The projection moves to the component

- [x] 1.1 A timeline projection beside the observation model: actions grouped by `actionKey`, one
      segment per interval between consecutive events, a segment marked as a wait when the event
      that closes it starts the work, a mark per event. It is Magento's
      `ProcessDetail::getTimeline()` — the richer of the two — moved
- [x] 1.2 **Not moved as-is: the percentages.** `scale()` returns 0–100 floats, which is a drawing,
      and the component would then be emitting CSS widths to a surface that renders no CSS. The
      projection carries an offset from the run's first event and a length, both in seconds; each
      host scales them, and the rule that a four-millisecond wait does not draw wider than six
      milliseconds of work binds whoever scales
- [x] 1.3 Its unit tests move with it, plus the three cases neither surface covers today: a single
      event action, a run whose events all fall in the same microsecond, and a run still going
- [x] 1.4 A run-list projection beside it: description, outcome counters over the page, paging state
      and the backend state — `RunDashboardView` minus everything Sylius-shaped, which is nothing
- [x] 1.5 The backend state becomes three cases rather than a boolean; the ephemeral case carries
      what to configure, without the host having to word it

## 2. Sylius renders the projection instead of deriving its own

- [x] 2.1 `RunDashboardView` builds on the promoted projection; its private `actions()` goes —
      done with 1.4: the class was **moved**, not copied, and leaving two copies in the tree for
      the length of a slice would have cost more than rewiring three lines
- [x] 2.2 The detail panel positions actions in time and hatches a wait, which it does not do today
- [x] 2.3 `RecordedDetails` in the core: the Sylius template called `json_encode` **without**
      tolerance and rendered an empty disclosure as soon as one byte was not UTF-8. Measured before
      writing, and the scenario corrected along with it: partial output **never** returns `false` —
      not on an invalid byte, not on a resource, not on six hundred levels of nesting. The right
      degradation is therefore not the single line but the whole payload with only the offending
      value as `null`, which is better than what the spec asked for. The `false` guard stays,
      defensively
- [x] 2.4 A **real** render of the template, not a reading of its text: the other assertions in the
      folder read the file, and none exercised `action.events` → `mark.event.label`. A misnamed
      property in that chain renders an empty page on the very screen one came to look at.
      Verified by mutation. Since 2.2/2.3 it covers a hatched wait, placement in time and a
      payload with an invalid byte

## 3. Magento renders the projection instead of deriving its own

- [x] 3.1 `ProcessDetail` consumes the promoted projection. What must **disappear**, not merely
      coexist: `getTimeline()`, `segments()`, `scale()`, the composition of the segment and mark
      tooltips (a duplicate of `TimelineSegment::$title` / `TimelineEvent::$title`) and
      `formatDetails()` (a duplicate of `RecordedDetails::of()`). Leaving them side by side would
      bring back exactly the divergence slice 1 went after
- [x] 3.2 The listing reports backend health, which it never probes today
- [x] 3.3 The counters cover **the window the screen reads**, and say so. Not "the page": Magento's
      grid pages by offset *inside* that window, so the set the operator browses is the window,
      not the current page. The author's decision — a scope owned and named — holds for both; it
      is the scope that differs, because the paging differs. `RunDashboard::outcomeCounters()`
      becomes public: counting by hand in the host would dig the forgotten-bucket hole again
- [x] 3.4 The ceiling is announced as soon as the window is full, and the window is **a single
      constant** — `RuntimeFactory::OBSERVATION_WINDOW`. They were two literals with the same
      value, which made it possible to be listed on one side and unfindable on the other the
      first time someone changed one

## 4. Counters and absences say what they mean

- [x] 4.1 The label names the scope on both surfaces: "Outcomes across the N runs on this
      page" on Sylius, "across the N most recent runs this screen reads" on Magento — the
      scope differs because the paging differs, and each says so
- [x] 4.2 The Magento grid rendered `''` for an absent date — an empty cell reads as a render
      that failed. A named em dash, the same one as on the detail screen. The Sylius list has no
      fixed columns: they are cards, and an absent fact is omitted there, which remains the
      right rendering — the em dash rule applies to tables

## 5. Sweep the drift lanes left behind

- [x] 5.1 `WorkflowRunEventKind` no longer describes a lane but a **kind**: the row comes from
      the action, and the enumeration now serves only for colour. Also swept in the two history
      readers and in the CSS classes of the Sylius template (`durable-lane` → `durable-action`)
- [x] 5.2 The plugin README describes the timeline by action, and its "Lane kind" table becomes
      "Event kind"
- [x] 5.3 Both READMEs carry the **same section** "The panels, and why they are the same
      everywhere" — four panels, the three backend states, the counter scope, the timeline
      by action. The site's package pages follow, in both languages; the site mounts
      `documentation/user` directly, so there is no copy to maintain

## 6. Leave the decision behind

- [x] 6.1 `DUR049 — One projection, two chromes`, indexed in `documentation/INDEX.md`. It carries
      the four defects **measured** rather than assumed (health never probed, the empty disclosure
      on an invalid byte, two times for the same event on one page, the timeline without any test)
      and the four rejected alternatives — among them "promote the Sylius model", which would
      have levelled down
- [x] 6.2 A `documentation/user/dashboard/` page, in both languages: the four panels, the three
      backend states, the timeline by action, the counter scope, the two absences. The
      `durable-plugin` and `durable-magento` sections of the packages page no longer each describe
      their timeline — they describe their **chrome** and point to it

## Slice 1 notes

`ProcessDetail::getTimeline()` had **no tests** — `tests/unit/DurableModule/` contains only
`DeclaredRuntimeTest` and `RuntimeFactoryTest`. The eleven cases of
`TheRunTimelinePositionsActionsInTimeTest` are therefore the first coverage of that logic, not a
move of existing tests; three of them (a single-event action, a run fitting in one microsecond, a
run still going) are the ones §1.3 asked for and that no surface covered.

The third backend state cost only one defaulted parameter: `BackendHealth::$ephemeral`, set to
`false`. The three catalogues that write outside the process — SQL, Illuminate, Temporal — have
nothing to declare; only `InMemoryWorkflowRunCatalog` passes `true`.

## Slice 2 notes

Two things moved up into the projection rather than being written twice: the **tooltips**
(`TimelineSegment::$title`, `TimelineEvent::$title`) and the **formatting of the payload**
(`TimelineEvent::$renderedDetails`). Magento composed them in PHP, Sylius did not have them; leaving
them to the host would have let the words two surfaces say about the same second diverge. The raw
fact stays on `$event->details` for a surface that serves data rather than a page.

The rule "a four-millisecond wait does not draw wider than six milliseconds of work" is held by a
**uniform** `min-width`: below the threshold both bars are equal, never inverted. It lives in the
host, with the percentages, as §1.2 decided.

⚠ **A time zone caught along the way.** The timeline tooltip is composed in the core, with the
time zone the event carries; Twig's `date` filter applies the **server's**. On a machine in
Paris, the same event read 22:13:20 on hover and 23:13:20 in the line just below — in a page whose
whole reason to exist is that an operator has nothing to convert in their head. `date(..., false)`
keeps the date's time zone. The test runs under `Europe/Paris`: under UTC the divergence is
invisible, and UTC is what CI runs under.

## Slice 3 notes

What **disappeared** from `ProcessDetail`, and that was the point: `getTimeline()`, `segments()`,
`getEvents()`, `actionLabel()`, `formatDetails()`, and the two tooltip compositions. What remains
is `scale()` — seconds to a percentage of track — which does belong to the host: scaling requires
knowing a column width. `RuntimeFactory::hasCluster()` goes too: ephemerality comes from the port;
it is the in-memory catalogue that knows it is ephemeral, not the host guessing from a missing DSN.

⚠ **No CI tool analyses a `.phtml`.** PHPStan and Psalm run against Magento's real classes in the
job that installs the distribution, but the templates escape them — and those two had just been
rewritten onto an object API where they used to read arrays. Two render tests now cover them, with
a block double and a global `__()`; they need neither Magento nor a database, so they run in the
ordinary suite. Verified by mutation.

Two calls to `listRuns()` per grid display — the banner counts, the provider lists. Accepted and
commented: the alternative would be coupling the banner to the grid's provider, or a request cache
around the catalogue. The latter is the way out if it becomes a burden.

## Slices 4 and 5 notes

The em dash rule applies only to **tables**. The Sylius list is made of cards: an absent fact is
omitted there, and that is the right rendering — there is no column to leave empty. It was the
Magento grid that rendered `''`, and an empty cell there reads as a render that failed.

An identical section in both READMEs rather than a link from one to the other: they are published
in two separate satellite packages, and a reader of `durable-magento` on Packagist does not have
the `durable-plugin` one at hand.

What remained of "voies" (lanes) elsewhere was ordinary French — "se voient", "deux voies" — and
was not touched. `DUR037` also keeps some: it is an ADR, it says what was decided at its date, and
it is §6.1 that supplements it rather than rewriting it.

## Slice 6 notes

`DUR049` was reread against the code before being frozen, and two figures corrected:
`ProcessDetail` goes from eleven methods to seven — it keeps `getTimeline()` as a memoised
accessor, it does not lose six — and the timeline has eighteen test cases, not eleven. An ADR that
counts wrong is read once and then never again.

On the site, duplication did not have the same excuse as in the READMEs: two satellite packages
justify a repeated section, a single site does not. Hence **one** page, and two chrome sections
that point to it.

⚠ **The home page is deliberately left untouched.** `hugo-docs/layouts/index{,.fr}.html` is
**generated** from `variant-b-narrative{,-fr}.dc.html`: the sentences of the host selector are
changed through the canvas, never in the generated file. They contradict nothing today —
"Sylius admin gains a dashboard", "Filament … the dashboard side" remain true — but the
Magento line does not mention its screen, and that is an omission to take up in the canvas.

⚠ **The installed hugo is a snap**: it reads neither `/tmp` nor hidden folders in `$HOME`.
Checking a build from a scratchpad worktree therefore requires copying `hugo-docs/` and
`documentation/` (2 MB) to a path visible under `$HOME`. `--minify` build served over HTTP, both
languages and relative links checked at 200.
