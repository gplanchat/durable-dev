# Tasks

## 1. Find out what the server already records

- [ ] 1.1 Probe: run a versioned workflow against a real server using an official SDK, dump the
      history, and record exactly which event carries the version and what it holds.
- [ ] 1.2 Probe: emit that same event from the Durable bridge and confirm the server accepts it
      from a client that is not an official SDK.
- [ ] 1.3 If 1.2 fails, record the failure and fall back to a Durable-owned journal event — and
      state in `design.md` that Temporal UI legibility was lost, and why.
- [ ] 1.4 Replace this design's assumption section with what 1.1–1.3 found.

## 2. The primitive

- [ ] 2.1 RED: a run reaches the marker on old code, is replayed on new code, and still sees the
      old version. Two workflow classes, one history.
- [ ] 2.2 GREEN: the marker on the authoring surface, the event in the journal, resolution from
      history on replay.
- [ ] 2.3 RED/GREEN: a run reaching the marker for the first time on new code sees the new version
      and records it.
- [ ] 2.4 RED/GREEN: the same run replayed twice sees the same version twice.

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
- [ ] 4.3 Check whether the run observation projection can already answer "which live runs recorded
      version N of change point X". If it can, no feature is needed — document the query.

## 5. Say it in the documentation

- [ ] 5.1 A DUR: the primitive, the wire representation actually used, and the removal rule.
- [ ] 5.2 A user page: marking a change, and how to know when an old branch can be deleted.
- [ ] 5.3 The comparison page's versioning row stops describing a gap.
- [ ] 5.4 Keep the workflow-type-rename strategy documented. It stays the right answer for a change
      too large to express as a branch.
