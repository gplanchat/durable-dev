<?php

namespace unit\DurableRector\Fixture\Facade;

// No workflow contract, no facade call: `yield` here is ordinary PHP.
class AttributeReader
{
    public function options(\ReflectionMethod $method): iterable
    {
        foreach ($method->getAttributes() as $attribute) {
            yield $attribute->newInstance();
        }
    }
}

?>
