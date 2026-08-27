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

- [ ] 2.1 RED: a run reaches the marker on old code, is replayed on new code, and still sees the
      old version. Two workflow classes, one history.
      **Not done, and it needs something the engine does not have.** The primitive below covers a
      run that *recorded* a version. This scenario is the other one: a run that passed this point
      **before the change point existed**, whose journal therefore holds no marker at all. Temporal
      answers `DefaultVersion` there, and knows to, because its SDK tracks whether the current task
      is replaying recorded history or producing new work. Durable has **no such signal** — there is
      no `isReplaying` anywhere in the engine, and the history source cannot say whether a call
      sits inside the replayed prefix or past its end.
      Today `version()` would hand such a run the *new* behaviour, which is the opposite of what
      versioning is for. Closing it means adding a replaying signal to the port and both history
      sources; that is its own slice, and guessing at it here would have shipped the bug quietly.
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

- [ ] 3.1 Assert the replay divergence guard does **not** fire on a versioned workflow whose
      branches differ — with the guard in place, not stubbed.
- [ ] 3.2 Assert the version event is journaled before the slots it decides, and read before them
      on replay. A test that fails if the ordering is inverted.
- [ ] 3.3 Assert the guard still fires on an unversioned change in the same workflow: versioning
      one change point does not disarm the guard for the others.

## 4. Both backends

- [ ] 4.1 The same three behaviours on the DBAL backend.
- [ ] 4.2 Integration suite against a real server, green.
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
