<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Durable\WorkflowStartOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkflowClientInterfaceStartOptionsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function startMethods(): iterable
    {
        yield 'startAsync' => ['startAsync'];
        yield 'startSync' => ['startSync'];
    }

    #[DataProvider('startMethods')]
    public function testInterfaceDeclaresNullableStartOptionsDefaultingToNull(string $method): void
    {
        $parameters = (new \ReflectionMethod(WorkflowClientInterface::class, $method))->getParameters();

        self::assertCount(4, $parameters);
        self::assertSame('options', $parameters[3]->getName());
        self::assertSame('?' . WorkflowStartOptions::class, (string) $parameters[3]->getType());
        self::assertTrue($parameters[3]->isDefaultValueAvailable());
        self::assertNull($parameters[3]->getDefaultValue());
    }
}
