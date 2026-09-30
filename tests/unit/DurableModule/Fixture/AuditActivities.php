<?php

declare(strict_types=1);

namespace unit\DurableModule\Fixture;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/** A second activity contract, to give a handler something it must not be served for. */
interface AuditActivities
{
    #[AsActivityMethod(name: 'test.audit.record')]
    public function record(string $entry): string;
}
