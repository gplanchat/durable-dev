# Tasks

## 1. Find out what the server already records

- [x] 1.1 A versioned workflow written with the **Go SDK** and run against a real server. The
      version travels in `EVENT_TYPE_MARKER_RECORDED`, `markerName: "Version"`, with `details`
      carrying `change-id` and `version` as `json/plain` payload lists.
- [x] 1.2 The bridge emitted exactly that marker and the server **accepted** it. The resulting
      history is byte-identical to the Go SDK's on marker name, change id and version.
- [x] 1.3 **Not needed.** 1.2 succeeded, so the Durable-owned fallback and its loss of Temporal UI
      legibility do not apply. Recorded as a finding rather than a decision deferred.
- [x] 1.4 `design.md`'s assumption section replaced by what was measured — including the difference
      found on the way: the Go SDK also upserts the standard `TemporalChangeVersion` search
      attribute, which is what makes "who is still on version N" a query rather than a feature.
      That moved work **into** the primitive (2.2) and **out of** 4.3.

## 2. The primitive

- [x] 2.1 A run that passed this point **before it existed** keeps the old behaviour.
      Its journal holds no marker, and the answer is `DEFAULT_VERSION`.
      **The signal this needed turned out to already be there.** Temporal knows because its SDK
      tracks whether the current task is replaying; this engine has no such flag — but it does not
      need one. The question "is this call inside the replayed prefix" is answerable from the port
      as it stands: if the **next** slot of any kind is already recorded, there is work ahead that
      this pass has not reached, so the call sits in the prefix. Four existing lookups, no new port
      method.
      Deduced rather than stored, so it is deterministic: two replays of the same history answer
      the same, which is the only property versioning needs. And nothing is written — an old run
      is not marked, it is recognised.
      **The gap, stated:** side effects are not consulted. `findSideEffectForSlot()` returns
      `mixed`, and a recorded value may legitimately be `null`, so "nothing here" and "here, the
      value null" are indistinguishable. A workflow whose only work before a change point is a side
      effect is therefore treated as new. Narrow, and written down rather than discovered later.
      Checked that the tests discriminate: with the signal neutralised, three of them fail.
- [x] 2.2 GREEN: `WorkflowEnvironment::version()`, `VersionMarked` in the journal, resolution from
      history on replay, and the `TemporalChangeVersion` upsert alongside the marker.
      Verified against a real server: a versioned Durable workflow produces a history **identical**
      to the Go SDK's — same events, `markerName: Version`, `change-id`/`version`, and the search
      attribute as a `KeywordList ["ajout-remise-1"]`. Both runs come back from the same
      `temporal workflow list --query 'TemporalChangeVersion = …'`.
      Two things the bridge forced: the `Version` marker needs its **own branch before** the
      side-effect one, or it consumes a side-effect slot and shifts every later replay — the file
      already carried a comment warning of exactly that; and the four protobuf map constructions
      became one factory, which removed a Psalm baseline entry rather than growing it.
- [x] 2.3 RED/GREEN: a run reaching the marker for the first time sees the newest supported version
      and records it — once, not on every call.
- [x] 2.4 RED/GREEN: the same run replayed twice sees the same version twice, and a newer deployed
      code does not move it. A second test pins that two change points are independent: an execution
      can be on the old side of one and the new side of another.

## 3. The guard sanctions it

- [x] 3.1 The guard does **not** fire on a branch the version decided — guard in place, not stubbed.
      Both directions are held: a run on version 1 takes the new branch and its slot resolves, and a
      run on `DEFAULT_VERSION` takes the old one and its slot resolves too. Both branches are
      legitimate, each for the execution it concerns.
- [x] 3.2 The version is decided and journaled **before** the slot it commands. The test reads the
      journal back and asserts the marker's position is lower than the activity's. If the order were
      inverted, an execution would have used an answer it did not yet have.
- [x] 3.3 Versioning one change point does **not** disarm the guard elsewhere: with the versioned
      branch respected and a *different* slot changed without declaring a point, the guard still
      raises. The exception covers only what it names.

**What this section found: nothing had to be built.** The design expected the guard to need teaching
about version markers. It does not, and the reason is worth recording — the guard compares what the
code asks for against what history recorded, and a versioned run asks for exactly what it recorded,
*because its version came from that same history*. The sanctioned exception is not a special case in
the guard; it falls out of both mechanisms reading the same journal.

Checked that the tests discriminate rather than pass by construction: making an old run take the new
behaviour fails one, and recording the version after the slot instead of before fails another.

## 4. Both backends

- [x] 4.1 Not a DBAL-specific test: the conformance workflow now **declares a change point**, and
      `EventStoreReplayConformanceTestCase` asserts the recorded version survives the round trip
      through the store. Every adapter that extends it inherits the check — DBAL today, whatever
      comes next tomorrow.
      A store that lost `VersionMarked` would put an in-flight execution back on the other branch
      **in silence** — the divergence guard would see code consistent with the new version and say
      nothing. That is why this belongs in the shared suite rather than in one adapter's tests.
      The suite also pins that an undeclared change point answers `null` and not `0`: an adapter
      that returned `0` would hand the old branch to an execution that never earned it.
- [ ] 4.2 Integration suite against a real server, green.
      **Blocked, and not by this change.** Running the full suite surfaced that
      `ReplayDivergenceGuardTest` — added by `workflow-replay-divergence-guard` §3.2 — hangs when
      run in the suite, where it passed in isolation. The run reaches `WORKFLOW_TASK_FAILED` as
      intended and the assertions pass; what fails is the recovery: after
      `redeployWorkflowWorker('default')` the workflow task keeps being retried (observed at
      **attempt 17**, `PENDING_WORKFLOW_TASK_STATE_STARTED`) and the execution never completes, so
      `pollForCompletion` waits out its budget.
      58 of the suite's tests pass before it; nothing in the versioning work is implicated. Fixing
      that test is its own slice — it is a defect in the harness added by the previous change, and
      folding it in here would hide it.
- [x] 4.3 Answered by the probe, and not where it was expected: the **server** answers it, through
      the standard `TemporalChangeVersion` search attribute the Go SDK upserts beside the marker.
      `temporal workflow list --query 'TemporalChangeVersion = "<change-id>-<version>"'` was run and
      returns the matching executions. No feature — a query, conditional on 2.2 writing the upsert.
      On the journal backends, which have no search attributes, this stays an open question and the
      user page should say so.

## 5. Say it in the documentation

- [ ] 5.1 A DUR: the primitive, the wire representation actually used, and the removal rule.
- [ ] 5.2 A user page: marking a change, and how to know when an old branch can be deleted.
- [ ] 5.3 The comparison page's versioning row stops describing a gap.
- [ ] 5.4 Keep the workflow-type-rename strategy documented. It stays the right answer for a change
      too large to express as a branch.
