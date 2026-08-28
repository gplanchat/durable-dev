<?php

namespace unit\DurableRector\Fixture\AttributesAlpha8;

use Gplanchat\Durable\Attribute\QueryMethod;
use Gplanchat\Durable\Attribute\SignalMethod;
use Gplanchat\Durable\Attribute\UpdateMethod;

final class Panier
{
    #[QueryMethod('etat')]
    public function etat(): string
    {
        return 'ok';
    }

    #[SignalMethod('reveille')]
    public function reveille(): void
    {
    }

    #[UpdateMethod('ajoute')]
    public function ajoute(int $n): int
    {
        return $n;
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\AttributesAlpha8;

use Gplanchat\Durable\Attribute\QueryMethod;
use Gplanchat\Durable\Attribute\SignalMethod;
use Gplanchat\Durable\Attribute\UpdateMethod;

final class Panier
{
    #[\Gplanchat\Durable\Attribute\AsQueryMethod('etat')]
    public function etat(): string
    {
        return 'ok';
    }

    #[\Gplanchat\Durable\Attribute\AsSignalMethod('reveille')]
    public function reveille(): void
    {
    }

    #[\Gplanchat\Durable\Attribute\AsUpdateMethod('ajoute')]
    public function ajoute(int $n): int
    {
        return $n;
    }
}

?>
