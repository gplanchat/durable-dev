<?php

declare(strict_types=1);

namespace unit\DurablePhpstan;

use PHPUnit\Framework\TestCase;

/**
 * `#[Activities]` tells the loader the contract at run time; `@param ActivityStub<T>` tells PHPStan.
 * Two statements of one fact can disagree, and the rule is what keeps them from doing so.
 *
 * Checked as {@see StubMethodsExtensionTest} is: by running PHPStan over a fixture.
 */
final class ActivitiesParameterRuleTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/ActivitiesParameters.php';

    public function testAnAgreeingDocblockIsNotReported(): void
    {
        self::assertSame([], $this->matching($this->analyse(), '$agreeing'));
    }

    public function testADisagreeingDocblockIsReported(): void
    {
        self::assertNotSame([], $this->matching($this->analyse(), 'Parameter $disagreeing is #[Activities(unit\DurablePhpstan\Fixtures\OrderActivities::class)] but its @param says ActivityStub<unit\DurablePhpstan\Fixtures\ShippingActivities>'));
    }

    public function testANullableStubWithItsGenericIsNotReported(): void
    {
        self::assertSame([], $this->matching($this->analyse(), '$nullable'));
    }

    public function testAContractGivenAsAStringIsCheckedToo(): void
    {
        self::assertNotSame([], $this->matching($this->analyse(), 'Parameter $namedByString is #[Activities(unit\DurablePhpstan\Fixtures\OrderActivities::class)] but its @param says'));
    }

    public function testAContractNamedAfterTheOptionsIsCheckedToo(): void
    {
        // #[Activities] takes named options: the contract is not always the first argument.
        self::assertNotSame([], $this->matching($this->analyse(), 'Parameter $reordered is #[Activities(unit\\DurablePhpstan\\Fixtures\\OrderActivities::class)] but its @param says'));
    }

    public function testAMissingDocblockIsReportedWithItsFix(): void
    {
        // Without the generic, every call on the stub is already "undefined method". The rule
        // reports the cause once, where it can be fixed.
        self::assertNotSame([], $this->matching($this->analyse(), 'Parameter $undocumented is #[Activities(unit\DurablePhpstan\Fixtures\OrderActivities::class)] but has no @param ActivityStub<OrderActivities>'));
    }

    /**
     * @return list<string>
     */
    private function analyse(): array
    {
        $root = \dirname(__DIR__, 3);
        $config = tempnam(sys_get_temp_dir(), 'durable-phpstan-') . '.neon';
        file_put_contents($config, 'includes:' . "\n    - " . $root . "/src/DurablePhpstan/extension.neon\n"
            . "parameters:\n    level: 5\n    paths:\n        - " . self::FIXTURE . "\n        - " . __DIR__ . "/Fixtures/StubCallSites.php\n");

        $command = array_map(escapeshellarg(...), [$root . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '-c', $config]);
        $out = (string) shell_exec(implode(' ', $command) . ' 2>/dev/null');
        unlink($config);

        /** @var array{files?: array<string, array{messages: list<array{message: string}>}>} $decoded */
        $decoded = json_decode($out, true) ?: [];
        self::assertArrayHasKey('files', $decoded, 'PHPStan returned nothing usable');

        $messages = [];
        foreach ($decoded['files'] as $file) {
            foreach ($file['messages'] as $message) {
                $messages[] = $message['message'];
            }
        }

        return $messages;
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private function matching(array $errors, string $needle): array
    {
        return array_values(array_filter($errors, static fn(string $m): bool => str_contains($m, $needle)));
    }
}
