# ADR011 — Child workflows, continue-as-new, and parent cancellation policy

## Context

The Durable component follows Temporal for child workflows and continue-as-new. This document describes accepted gaps and the public surface.

## Decisions

### 1. childWorkflowStub — typed API

The `childWorkflowStub(WorkflowClass::class, ?ChildWorkflowOptions)` API provides a typed proxy to start a child workflow. Each call to the contract’s `#[WorkflowMethod]` delegates to `executeChildWorkflow(workflowType, input, options)`.

Aligned with Temporal `newChildWorkflowStub` (typed stub, options).

### 2. Continue-as-new — run correlation gap

**Temporal**: continue-as-new keeps the **Workflow Id** and creates a new **Run Id**; history is chained via server metadata.

**Durable**: `continueAsNew(workflowType, payload)` creates a **new execution** (new `executionId`). Correlation for the “same logical workflow” is not exposed at identity level — each run is a distinct `executionId`.

**Decision**: accept this gap for now. Logical chaining can be carried in `payload` (e.g. business `workflowId`) if needed. A future change could add a `correlationId` or stable `workflowId` field in the log.

### 3. Parent close policy

`ChildWorkflowOptions` already exposes `ParentClosePolicy` (Terminate, Abandon, RequestCancel). The surface is complete for the current model.

## References

- [OST004](../ost/OST004-workflow-temporal-feature-parity.md)
- [Child Workflows — Temporal PHP](https://docs.temporal.io/develop/php/child-workflows)
- [Continue-As-New — Temporal PHP](https://docs.temporal.io/develop/php/continue-as-new)
