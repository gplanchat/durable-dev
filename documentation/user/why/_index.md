---
title: Why Durable
weight: 1
---

# Why Durable

Some work does not fit in a request. Charging a card, reserving the stock and emailing a receipt is
one business operation, but it touches three systems, takes longer than a connection stays open, and
can be interrupted between any two steps by a deploy, a crash or an OOM kill.

PHP has no built-in answer for that, so every codebase invents one. Durable is the answer written
once.

## You already have this problem if

Look for these in your own code. Each one is a piece of durable execution, hand-built:

- a **status column that means *maybe*** — `pending`, `processing`, `in_progress` — and nobody is
  sure which rows are stuck;
- an **idempotency key** you wrote yourself, because a retry charged a customer twice once;
- a **reconciliation job** that runs nightly to find the operations that stopped halfway;
- a **repair script**, run by hand in production, when a batch dies in the middle;
- a **retry counter and a dead-letter table**, plus the runbook that says what to do with them;
- no way to answer *why did order 4242 stop three days ago* except reading logs.

Those six exist to make a process survive an interruption. Durable makes the process survive
directly: the runtime records each completed step in a **journal**, and after a restart it replays
the method, returning recorded results instead of running those steps again. The process resumes on
the line it was on.

A worker can be redeployed mid-process. Nothing is charged twice, nothing is lost, and no cron is
involved.

## What it replaces

One method, and the journal behind it, instead of:

| You maintain today | What answers it instead |
|---|---|
| A state column and the migration that adds the next state | The line the method is on — the journal holds the position |
| A scheduler that polls for what is due | The next statement; timers and signals wake the execution |
| A retry counter and a dead-letter table | `RetryLimit::ofAttempts(3)`, an option on the activity stub |
| Idempotency keys, so a retry does not double-charge | A recorded step returns its recorded result — it cannot run twice |
| Reading logs to learn why an execution stopped | Replay its journal: every step, every result, every attempt |

The [home page](/) walks through the same order, step by step, showing what happens with and
without. It is the fastest way to see the mechanism if you have five minutes and no code in front
of you.

## When you do not need it

Durable is not free: it adds a journal to write, workers to run, and a determinism rule your
workflow code has to respect. Skip it when:

- the work **fits in one request** and has no external side effect worth recovering — rendering a
  page, a search query, a report you can simply run again;
- the work **can safely restart from scratch**. A nightly export that rewrites the whole file loses
  nothing by being retried from the top; a partial charge does;
- your queue consumers are **already idempotent and already observable**, and you can answer *what
  happened to this job* without opening a log file. You have built the thing; you do not need it
  twice;
- there is **exactly one side effect**. A single `INSERT` in a transaction is already atomic. The
  problem starts at the second step, when the first one has already happened and cannot be rolled
  back.

A good rule: if losing your place mid-process costs money, stock or a customer's trust, the process
wants a journal. If it costs a re-run, it does not.

## Where to go next

| | |
|---|---|
| [Concepts](../concepts/) | the vocabulary — workflow, activity, journal, replay — before the guides |
| [Getting started](../getting-started/) | install, configure, and write a first workflow |
| [Packages](../packages/) | what to install for your framework, and which backend |
| [Durable and the Temporal PHP SDK](../comparison/) | if you have decided on durable execution and are choosing between the two |

The last one assumes the decision this page is about is already made. Read it second.
