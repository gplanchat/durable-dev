---
title: Parity between the surfaces
weight: 40
---

# Parity between the surfaces

What each surface shows, checked against the sources of `main` on 2026-09-30. The [overview](../)
says why the dashboard is one; this page lists where the four surfaces still differ. It changes
when a gap closes.

## What each surface shows

| | Sylius | Magento | Filament | Web profiler |
| --- | --- | --- | --- | --- |
| Runs listed | All | The 200 most recent | All | Those seen in one request, plus 20 named in `durable_execution` at most |
| Backend state | 4 states, 3 of them dated | 3 states, the in-memory one undated | 4 states, 3 of them dated | None |
| Counters | Per outcome, over the page | Per outcome, over the window | Per outcome, over the page | None |
| Outcome filter | Yes | Yes | No | No |
| Workflow name filter | Whole name, where the backend can | Contains, ignoring case, within the window | Whole name, where the backend can | No |
| Execution id filter | Prefix, where the backend can | Contains, within the window | Prefix, where the backend can | No |
| `waiting for a worker` | Line and counter | No | Line and counter | No |
| `waiting on` | List | List and run page | List and run page | Run section |
| One line per action | Yes | Yes, plus a journal table | Yes | No, one row per event |
| Hatched queue time | Yes | Yes | On the timeline | No |
| Red on the failing event | Yes | Yes | On the timeline | No |
| Nexus operations table | Yes | Yes | Yes | Yes |
| Worker presence | Yes | Yes, journal and activity | No | No |
| Payload masking | Folded | Folded | Folded | Always open |
| Languages | English, French | English | English, French | English |

## Words on screen

| | Sylius | Magento | Filament | Web profiler |
| --- | --- | --- | --- | --- |
| Heading for the outcome | Outcome | Status | Outcome | Status |
| Heading for the recorded history | Run details | Timeline, Journal | History | Event history |
| Heading for the run id | Execution | Execution, Run | Execution | Workflow ID |
| Outcome value | Translated, upper case | Enum value on the run page, first letter capitalised in the grid | Translated | Own vocabulary: Finished, Queued (no journal yet), Pending, Cancellation requested, Continue as new |

## Behaviours worth knowing

- **Magento counters.** They cover the whole 200-run window and ignore the grid filters.
- **Filament outcome.** The list reads every outcome.
- **Sylius run page.** It does not show the `waiting on` line, which the list does.
- **Blank cells.** The Sylius grid leaves the start date blank when a run has none, and the
  Filament Notes column leaves an empty note blank. Magento and the profiler print a dash.
- **Continued as new.** Grey on Sylius and Filament, purple in the profiler, uncoloured on Magento.
- **Profiler.** Only runs dispatched during the request appear, plus those named in the
  `durable_execution` query parameter. See [the profiler page](../profiler/).

## See also

- [Sylius](../sylius/), [Magento](../magento/), [Filament](../filament/) and the
  [web profiler](../profiler/), one page each
