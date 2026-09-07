<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Demo\Contracts\Stock;

use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * What the caller sees of the `stock` service.
 *
 * It adds nothing for now, and that is deliberate. The split does not exist for what it separates
 * today but for what it lets one add tomorrow: an operation fulfilled by a workflow is declared
 * **here**, where the handler does not have to write an empty body for it.
 *
 * The caller therefore always reads `StockContract`, including while both interfaces carry the same
 * operations, since otherwise the day one of them diverges, every caller would need touching.
 */
#[AsNexusService('stock')]
interface StockContract extends StockServed {}
