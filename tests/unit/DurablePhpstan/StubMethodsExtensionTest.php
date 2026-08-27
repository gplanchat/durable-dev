<?php

declare(strict_types=1);

namespace unit\DurablePhpstan;

use PHPUnit\Framework\TestCase;

/**
 * L'extension est vérifiée en lançant PHPStan sur une fixture, avec puis sans elle.
 *
 * Un test qui n'exercerait que la classe en isolation prouverait qu'elle répond correctement à des
 * questions posées par le test lui-même — pas qu'elle change ce que PHPStan voit. C'est le
 * contraste qui est le test.
 *
 * Et ce contraste n'est pas celui qu'on imagine. Sans extension, PHPStan n'est pas aveugle : il
 * signale **tous** les appels de stub, corrects compris. Le défaut n'est donc pas le silence mais
 * le bruit — deux fausses erreurs pour deux vraies, qu'on met en ligne de base d'un bloc en
 * perdant les vraies avec. Ces tests épinglent ce fait, parce que c'est lui qui justifie le
 * paquet.
 */
final class StubMethodsExtensionTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/StubCallSites.php';

    public function testWithoutTheExtensionEvenCorrectCallsAreFlagged(): void
    {
        $errors = $this->analyse(withExtension: false);

        // `charge()` est déclarée par le contrat et marquée : l'appel est juste, et PHPStan le
        // refuse quand même. C'est le faux positif que l'extension supprime.
        self::assertNotSame(
            [],
            $this->matching($errors, 'charge()'),
            'sans extension, un appel correct doit être signalé — c\'est le défaut à corriger',
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
        self::assertNotSame([], $typo, 'la faute de frappe doit rester signalée');
    }

    public function testAContractMethodWithoutTheAttributeIsNotSchedulable(): void
    {
        $errors = $this->analyse(withExtension: true);

        // Déclarée par le contrat mais non marquée : le stub la refuse à l'exécution, l'analyse
        // doit la refuser aussi.
        self::assertNotSame([], $this->matching($errors, 'helper'));
    }

    public function testTheArgumentCountIsCheckedOnceTheMethodIsKnown(): void
    {
        $errors = $this->analyse(withExtension: true);

        // `charge()` attend deux arguments et n'en reçoit qu'un. Cette erreur n'existe que parce
        // que l'extension a rendu la méthode connue : c'est le gain que le bruit masquait.
        self::assertNotSame([], $this->matching($errors, 'invoked with 1 parameter, 2 required'));
    }

    public function testTheStubCallIsAnAwaitableAndNotTheContractReturnType(): void
    {
        $errors = $this->analyse(withExtension: true);

        // Le contrat déclare `: string`, mais le stub planifie et rend un Awaitable. Rendre la
        // réflexion du contrat telle quelle ferait refuser le `await()` qui suit — une faute qui
        // n'en est pas une.
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
        self::assertIsResource($process, 'PHPStan n\'a pas pu être lancé');

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
