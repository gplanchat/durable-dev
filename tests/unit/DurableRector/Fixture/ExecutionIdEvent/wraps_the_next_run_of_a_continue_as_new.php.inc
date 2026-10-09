<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Durable\Exception\ContinueAsNewRequested;

final class ContinuesAsNew
{
    public function __invoke(string $next, ?string $maybe): array
    {
        return [
            new ContinueAsNewRequested('Order', [], null, $next),
            new ContinueAsNewRequested('Order', [], null, $maybe),
            new ContinueAsNewRequested('Order', []),
        ];
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Durable\Exception\ContinueAsNewRequested;

final class ContinuesAsNew
{
    public function __invoke(string $next, ?string $maybe): array
    {
        return [
            new ContinueAsNewRequested('Order', [], null, \Gplanchat\Durable\ExecutionId::fromString($next)),
            new ContinueAsNewRequested('Order', [], null, $maybe),
            new ContinueAsNewRequested('Order', []),
        ];
    }
}

?>
