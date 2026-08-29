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
      fait avec 1.4 : la classe a été **déplacée**, pas recopiée, et laisser deux exemplaires dans
      l'arbre le temps d'une tranche aurait coûté plus que de recâbler trois lignes
- [ ] 2.2 The detail panel positions actions in time and hatches a wait, which it does not do today
- [ ] 2.3 An unrenderable detail degrades to a plain line, as Magento's already does — the Twig
      `json_encode` has no partial-output tolerance
- [ ] 2.4 The template render test covers a run with a wait and a run with an unrenderable payload

## 3. Magento renders the projection instead of deriving its own

- [ ] 3.1 `ProcessDetail` consumes the promoted projection; its `getTimeline()` and `segments()` go
- [ ] 3.2 The listing reports backend health, which it never probes today
- [ ] 3.3 The listing shows counters over the page it displays
- [ ] 3.4 The window ceiling is stated on screen, and the detail screen resolves a run without
      re-scanning a second, differently sized window

## 4. Counters and absences say what they mean

- [ ] 4.1 The `Total` heading becomes a heading that names the page, on both surfaces
- [ ] 4.2 A fact absent for this run renders as an em dash where columns are fixed; a fact the
      backend has no notion of still shows no column at all

## 5. Sweep the drift lanes left behind

- [ ] 5.1 `WorkflowRunEventKind`'s documentation stops describing lanes — actions replaced them
- [ ] 5.2 `src/DurablePlugin/README.md` stops advertising history "grouped in lanes" and its lane
      source table
- [ ] 5.3 `src/DurableModule/README.md` and `magento/README.md` describe the same panels

## 6. Leave the decision behind

- [ ] 6.1 An ADR: why the timeline projection lives in the component beside the observation model
      rather than in each surface or in a shared dashboard package, with `ReadableDuration` as the
      precedent it follows
- [ ] 6.2 The documentation site's dashboard pages describe one dashboard with several chromes,
      rather than one per host

## Notes de la tranche 1

`ProcessDetail::getTimeline()` n'avait **aucun test** — `tests/unit/DurableModule/` ne contient que
`DeclaredRuntimeTest` et `RuntimeFactoryTest`. Les onze cas de
`TheRunTimelinePositionsActionsInTimeTest` sont donc la première couverture de cette logique, pas un
déménagement de tests existants ; trois d'entre eux (action d'un seul événement, run tenant dans une
microseconde, run encore en cours) sont ceux que la §1.3 réclamait et qu'aucune surface ne couvrait.

Le troisième état de backend n'a coûté qu'un paramètre à défaut : `BackendHealth::$ephemeral`, à
`false`. Les trois catalogues qui écrivent hors du processus — SQL, Illuminate, Temporal — n'ont rien
à déclarer, seul `InMemoryWorkflowRunCatalog` passe `true`.
