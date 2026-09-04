<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Attribute\AsActivity;
use Gplanchat\Durable\Attribute\AsActivityMethod;

#[AsActivity(name: 'premier')]
interface PremierContract
{
    #[AsActivityMethod(name: 'faire')]
    public function faire(string $quoi): string;
}
