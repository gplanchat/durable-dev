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

#[\Gplanchat\Durable\Attribute\Activity(name: '')]
interface GreetActivities
{
    #[\Gplanchat\Durable\Attribute\ActivityMethod(name: 'greet')]
    public function greet(string $name): string;
}

?>
