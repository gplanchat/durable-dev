# DUR012 — API client layer and repository adapters

## Status

Accepted

## Context

Command and Query **repositories** (DUR002) talk to the **Temporal API** via a transport (gRPC/HTTP per implementation). Mixing **network calls**, **serialization**, **mapping** to component models, and **business rules** in one class makes tests hard and errors opaque.

## Decision

Two complementary responsibilities are separated:

### 1. Protocol client (“API” / transport layer)

- Handles **conversation** with the Temporal server: authentication if needed, **requests/responses** raw or near-raw, status codes, deadlines.
- Contains **no** host domain business logic; may encapsulate low-level retries **only** if they do not contradict DUR011.
- Remains **replaceable** in tests with a low-level fake or mock.

### 2. Repository adapter

- **Implements** the Durable component’s Command/Query ports.
- **Orchestrates** calls to the client: one repository operation may chain several calls when needed.
- **Maps** transported structures to **component types** (identifiers, internal DTOs, translated errors).
- **Translates** network or protocol failures into Durable / domain model errors (DUR011).

### Principles

- **Single dependency direction**: adapter → client → network; application domain depends on **repository interfaces**, not the client.
- **No leakage** of HTTP/gRPC client types into **public** signatures of stable component ports.
- **Serialization** of application payloads follows DUR007; the client may have its own envelope (headers, gRPC envelopes).

## Consequences

- Integration tests can target the adapter with a **fake client**; client tests with a **test server** or recorded responses.
- Temporal protocol evolution is **localized** to the client and mappers, not scattered across the codebase.
