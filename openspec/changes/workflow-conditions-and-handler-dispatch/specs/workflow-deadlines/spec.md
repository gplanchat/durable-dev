## REMOVED Requirements

### Requirement: Waiting for a signal under a deadline

**Reason**: The method this requirement describes is removed. A signal wait was the same mechanism
as a condition, one altitude lower, reading history directly instead of reading state a handler
deposited. Keeping it would leave two ingestion paths for one journaled message, free to disagree
on replay — and it is what forced the positional slot, the per-name consumption counter, the rule
for a wait that gave up without consuming, and the deadline-aware history lookup that had to be
aligned between the two backends.

**Migration**: Declare a handler for the signal and await a condition over what it records.

```php
// Before
try {
    $approval = $env->waitSignal(OrderSignal::Approve, Duration::hours(1));
} catch (DeadlineExceededException) {
    return $this->expire($orderId);
}

// After
$env->onSignal(OrderSignal::Approve, function (array $payload): void {
    $this->approvals[] = $payload;
});

try {
    $env->await(fn(): bool => [] !== $this->approvals, Duration::hours(1));
    $approval = array_shift($this->approvals);
} catch (DeadlineExceededException) {
    return $this->expire($orderId);
}
```

Every other deadline requirement is unchanged: bounding a wait in time, cancelling the losing
branch, and the stability of a verdict across replay all still hold — they now bound a condition
rather than a signal wait. The guarantee that an event recorded after the deadline does not undo the
timeout survives as a consequence of positional condition evaluation, stated in
`workflow-conditions`.
