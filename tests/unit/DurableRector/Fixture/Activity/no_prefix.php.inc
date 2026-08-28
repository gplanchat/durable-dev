<?php

namespace unit\DurableRector\Fixture\Activity;

#[\Temporal\Activity\ActivityInterface]
interface GreetActivities
{
    public function greet(string $name): string;
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Activity;

#[\Gplanchat\Durable\Attribute\AsActivity(name: '')]
interface GreetActivities
{
    #[\Gplanchat\Durable\Attribute\AsActivityMethod(name: 'greet')]
    public function greet(string $name): string;
}

?>
