## 1. Probe before encoding any rule

- [ ] 1.1 Probe what the server accepts as a Nexus header — empty key, empty value, whitespace, control characters, length, count — as was done for endpoint, service and operation names. **Write no invariant that was not observed.**
- [ ] 1.2 Probe whether the server rewrites or drops anything silently, and whether the header comes back unchanged in `NEXUS_OPERATION_SCHEDULED`

## 2. Domain

- [ ] 2.1 A header value object built on the probed rules, refusing only what the server refuses or what can only be a mistake
- [ ] 2.2 Unit tests asserting the probed verdicts, one case per observation

## 3. Port and backends

- [ ] 3.1 `WorkflowCommandBufferInterface::scheduleNexusOperation()` carries the headers — **BREAKING**, as DUR031 was
- [ ] 3.2 `TemporalWorkflowCommandBuffer` writes them into the command
- [ ] 3.3 `TemporalExecutionHistory` reads them back, if anything needs them on replay

## 4. Integration

- [ ] 4.1 A header sent through the bridge comes back unchanged in `NEXUS_OPERATION_SCHEDULED`, against a real server

## 5. Documentation

- [ ] 5.1 Update the `nexus-operations` spec: headers move from `MAY` to a capability actually offered
