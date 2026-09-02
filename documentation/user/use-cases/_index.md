---
title: Use cases
weight: 45
---

# Use cases

The rest of this guide is reference material: one page per feature — `await` here, signals there,
Nexus further on. This section does the opposite. Each entry is **a whole thing** — several
applications, several mechanisms, a problem that exists outside Durable — with its code in the
repository and a way to run it.

These are not advanced exercises. None of them is hard; they are *complete*. That is the only
thing separating them from an example in [Writing a workflow](../workflows/).

| | |
|---|---|
| [Four applications calling each other](nexus-demo/) | three frameworks, four Temporal namespaces, one shared contract — and an execution that serves one operation while calling another |

A second entry — an interruptible AI agent, its loop driven from workflow code — is waiting for its
prototype to settle. It will land with a way to run it: an entry pointing at something you cannot
start is not an entry.

## What an entry must contain

So the section stays readable as it grows, every page says, in this order:

1. **the problem**, stated without the word "Durable";
2. **what was built** — the files, and where they live;
3. **what Durable brings**, and above all **what it does not**;
4. **how to run it**;
5. **what is not proven.** An entry without that part is a brochure.
