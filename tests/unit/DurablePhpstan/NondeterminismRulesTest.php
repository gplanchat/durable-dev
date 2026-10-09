<?php

declare(strict_types=1);

namespace unit\DurablePhpstan;

use PHPUnit\Framework\TestCase;

/**
 * A workflow method is replayed from its journal, so whatever it reads from the clock or from a
 * random source must come out of the journal too (`$env->sideEffect()`, an activity). The rules
 * report the reads that do not: directly in the workflow class, through a date class or a clock
 * object, or in a helper the workflow calls.
 *
 * Checked as {@see ActivitiesParameterRuleTest} is: by running PHPStan over a fixture. The fixture
 * says what it expects on each line, with `// reported`.
 */
final class NondeterminismRulesTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/NondeterministicWorkflow.php';
    private const IDENTIFIER = 'durable.nondeterministic';

    public function testEveryMarkedLineIsReportedAndNoOtherLineIs(): void
    {
        $reported = array_keys($this->analyse());
        sort($reported);

        self::assertSame($this->linesEndingWith('// reported'), $reported);
    }

    public function testAReadFoundThroughHelpersNamesTheHops(): void
    {
        $messages = $this->analyse();
        $line = $this->lineOf('StampHelper::stamp(); // reported');

        self::assertArrayHasKey($line, $messages, 'the helper chain is not reported at the workflow call');
        self::assertStringContainsString('StampHelper::stamp', $messages[$line]['message']);
        self::assertStringContainsString('StampHelper::inner', $messages[$line]['message']);
        self::assertStringContainsString('time()', $messages[$line]['message']);
    }

    public function testTheReportSaysWhyAndWhatToDo(): void
    {
        $first = array_values($this->analyse())[0] ?? ['message' => '', 'tip' => ''];

        self::assertStringContainsString('differs on every replay', $first['message']);
        self::assertStringContainsString('sideEffect', $first['tip']);
    }

    /**
     * @return array<int, array{message: string, tip: string}> the errors of the rules, by line
     */
    private function analyse(): array
    {
        $root = \dirname(__DIR__, 3);
        $config = tempnam(sys_get_temp_dir(), 'durable-phpstan-') . '.neon';
        file_put_contents($config, 'includes:' . "\n    - " . $root . "/src/DurablePhpstan/extension.neon\n"
            . "parameters:\n    level: 5\n    paths:\n        - " . self::FIXTURE . "\n");

        // No shell: the arguments go to PHPStan as they are. PHPStan exits 1 when it reports errors.
        $process = proc_open(
            [$root . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '-c', $config],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        unlink($config);

        /** @var array{files?: array<string, array{messages: list<array{message: string, line: int, identifier?: string, tip?: string}>}>} $decoded */
        $decoded = json_decode($out, true) ?: [];
        self::assertArrayHasKey('files', $decoded, 'PHPStan returned nothing usable');

        $errors = [];
        foreach ($decoded['files'] as $file) {
            foreach ($file['messages'] as $message) {
                if (self::IDENTIFIER === ($message['identifier'] ?? null)) {
                    $errors[$message['line']] = ['message' => $message['message'], 'tip' => $message['tip'] ?? ''];
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<int> the 1-based lines of the fixture that end with the marker
     */
    private function linesEndingWith(string $marker): array
    {
        $lines = [];
        foreach (file(self::FIXTURE, \FILE_IGNORE_NEW_LINES) as $index => $text) {
            if (str_ends_with(rtrim($text), $marker)) {
                $lines[] = $index + 1;
            }
        }

        return $lines;
    }

    private function lineOf(string $text): int
    {
        foreach (file(self::FIXTURE, \FILE_IGNORE_NEW_LINES) as $index => $line) {
            if (str_contains($line, $text)) {
                return $index + 1;
            }
        }

        self::fail(sprintf('"%s" is not in the fixture', $text));
    }
}
