<?php

declare(strict_types=1);

namespace unit\DurablePhpstan;

use PHPUnit\Framework\TestCase;

/**
 * The extension is checked by running PHPStan over a fixture, with it and then without it.
 *
 * A test that exercised the class in isolation would prove it answers correctly the questions the
 * test itself asks — not that it changes what PHPStan sees. The contrast is the test.
 *
 * And the contrast is not the one you would imagine. Without the extension PHPStan is not blind: it
 * reports **every** stub call, the correct ones included. The defect is therefore not silence but
 * noise — two false errors for two real ones, baselined in one block and losing the real ones with
 * them. These tests pin that fact down, because it is what justifies the package.
 */
final class StubMethodsExtensionTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/StubCallSites.php';

    public function testWithoutTheExtensionEvenCorrectCallsAreFlagged(): void
    {
        $errors = $this->analyse(withExtension: false);

        // `charge()` is declared by the contract and marked: the call is right, and PHPStan
        // refuses it anyway. That is the false positive the extension removes.
        self::assertNotSame(
            [],
            $this->matching($errors, 'charge()'),
            'without the extension, a correct call must be reported — that is the defect to fix',
        );
        self::assertNotSame([], $this->matching($errors, 'run()'));
    }

    public function testACorrectCallStopsBeingFlagged(): void
    {
        $errors = $this->analyse(withExtension: true);

        foreach ($this->matching($errors, 'undefined method') as $message) {
            self::assertStringNotContainsString('::charge()', $message);
            self::assertStringNotContainsString('::run()', $message);
        }
    }

    public function testTheTypoIsStillReported(): void
    {
        $errors = $this->analyse(withExtension: true);

        $typo = $this->matching($errors, 'chrage');
        self::assertNotSame([], $typo, 'the typo must stay reported');
    }

    public function testAContractMethodWithoutTheAttributeIsNotSchedulable(): void
    {
        $errors = $this->analyse(withExtension: true);

        // Declared by the contract but not marked: the stub refuses it at run time, and the
        // analysis must refuse it too.
        self::assertNotSame([], $this->matching($errors, 'helper'));
    }

    public function testANexusOperationStopsBeingFlagged(): void
    {
        $errors = $this->analyse(withExtension: true);

        foreach ($this->matching($errors, 'undefined method') as $message) {
            self::assertStringNotContainsString('::charge()', $message);
        }
    }

    public function testAnInheritedNexusOperationIsCallableThroughTheStub(): void
    {
        // The caller's contract **extends** the one the handler implements. The extension must
        // follow that inheritance as the resolver follows it, or PHPStan would refuse the
        // operations the handler really serves.
        $errors = $this->analyse(withExtension: true);

        foreach ($this->matching($errors, 'undefined method') as $message) {
            self::assertStringNotContainsString('::verify()', $message);
        }
    }

    public function testANexusTypoIsStillReported(): void
    {
        $errors = $this->analyse(withExtension: true);

        self::assertNotSame([], $this->matching($errors, 'chagre'), 'the typo must stay reported');
    }

    public function testANexusContractMethodWithoutTheAttributeIsNotCallable(): void
    {
        $errors = $this->analyse(withExtension: true);

        self::assertNotSame([], $this->matching($errors, 'rateCard'));
    }

    public function testTheArgumentCountIsCheckedOnceTheMethodIsKnown(): void
    {
        $errors = $this->analyse(withExtension: true);

        // `charge()` expects two arguments and gets one. This error only exists because the
        // extension made the method known: it is the gain the noise was hiding.
        self::assertNotSame([], $this->matching($errors, 'invoked with 1 parameter, 2 required'));
    }

    public function testAReadonlyPropertyIsEnoughToCarryTheContract(): void
    {
        // The fixture declares its stubs `readonly` **without** a `@var` annotation. If this test
        // passes, PHPStan follows the constructor's generic parameter to the call site on its own —
        // measured, because the README first claimed the opposite.
        $errors = $this->analyse(withExtension: true);

        foreach ($this->matching($errors, 'undefined method') as $message) {
            self::assertStringNotContainsString('::charge()', $message);
        }
    }

    public function testTheStubCallIsAnAwaitableAndNotTheContractReturnType(): void
    {
        $errors = $this->analyse(withExtension: true);

        // The contract declares `: string`, but the stub schedules and returns an Awaitable.
        // Returning the contract's reflection as it stands would refuse the `await()` that follows
        // — an error that is not one.
        self::assertSame(
            [],
            $this->matching($errors, '$awaitable of method'),
            'await() ne doit pas se plaindre du type rendu par un appel de stub',
        );
    }

    /**
     * @return list<string>
     */
    private function analyse(bool $withExtension): array
    {
        $root = \dirname(__DIR__, 3);
        $config = tempnam(sys_get_temp_dir(), 'durable-phpstan-') . '.neon';

        $services = $withExtension
            ? "services:\n    -\n        class: Gplanchat\\Durable\\PHPStan\\Reflection\\StubMethodsExtension\n"
              . "        tags:\n            - phpstan.broker.methodsClassReflectionExtension\n\n"
            : '';

        file_put_contents($config, $services . "parameters:\n    level: 5\n    paths:\n        - " . self::FIXTURE . "\n");

        $process = proc_open(
            [$root . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '-c', $config],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($process, 'PHPStan could not be started');

        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($config);

        /** @var array{files?: array<string, array{messages: list<array{message: string}>}>} $decoded */
        $decoded = json_decode((string) $out, true) ?: [];
        self::assertArrayHasKey('files', $decoded, 'PHPStan n\'a rien rendu d\'exploitable');

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
