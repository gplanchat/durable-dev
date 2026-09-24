<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

/**
 * The configuration reference cannot drift from the tree (#365): its YAML block is what
 * `bin/console config:dump-reference durable` prints, and every key of the tree has its row in a key
 * table of the hand-written sections, as no row names a key the tree does not have.
 *
 * @internal
 */
final class ConfigurationReferenceTest extends KernelTestCase
{
    private const BEGIN = '<!-- generated: bin/console config:dump-reference durable -->';
    private const END = '<!-- end generated -->';

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'en' => [__DIR__ . '/../../../documentation/user/configuration/_index.md'];
        yield 'fr' => [__DIR__ . '/../../../documentation/user/configuration/_index.fr.md'];
    }

    #[DataProvider('pages')]
    public function testTheReferenceBlockIsTheDumpOfTheTree(string $page): void
    {
        $markdown = (string) file_get_contents($page);
        $begin = strpos($markdown, self::BEGIN);
        $end = strpos($markdown, self::END);
        self::assertNotFalse($begin, 'no generated block in ' . $page);
        self::assertNotFalse($end, 'no end of the generated block in ' . $page);

        $block = substr($markdown, $begin + \strlen(self::BEGIN), $end - $begin - \strlen(self::BEGIN));
        self::assertSame(
            "\n```yaml\n" . self::dump() . "```\n",
            $block,
            'The reference block is stale. Paste the output of `bin/console config:dump-reference durable` between the markers.',
        );
    }

    #[DataProvider('pages')]
    public function testEveryKeyHasItsRowAndNoRowNamesAKeyTheTreeLacks(string $page): void
    {
        $sections = self::sections((string) file_get_contents($page));
        $tree = Yaml::parse(self::dump())['durable'];
        self::assertIsArray($tree);

        $undocumented = [];
        $unknown = [];
        foreach ($tree as $top => $node) {
            $text = $sections[$top] ?? null;
            if (null === $text) {
                $undocumented[] = $top;
                continue;
            }
            $leaves = \is_array($node) && [] !== $node && !array_is_list($node) ? self::leaves($node) : [];
            // Only the key tables: a section also holds tables of values (backend) and of DSN parameters.
            preg_match_all('/^\| (?:Key|Clé) \|.*\n\|[-| ]+\|\n((?:\|.*\n?)*)/m', $text, $tables);
            preg_match_all('/^\| `([a-z0-9_.]+)` \|/m', implode("\n", $tables[1]), $rows);
            foreach (array_diff($leaves, $rows[1]) as $leaf) {
                $undocumented[] = $top . '.' . $leaf;
            }
            foreach (array_diff($rows[1], $leaves) as $row) {
                $unknown[] = $top . '.' . $row;
            }
        }

        self::assertSame([], $undocumented, 'keys of the tree with no row on ' . basename($page));
        self::assertSame([], $unknown, 'rows naming a key the tree does not have on ' . basename($page));
    }

    /**
     * The dump, with the one default that depends on the kernel written as what it is: whichever
     * boolean this kernel runs with would be wrong for half the readers.
     */
    private static function dump(): string
    {
        self::bootKernel(['debug' => false]);
        $tester = new CommandTester((new Application(self::$kernel))->find('config:dump-reference'));
        $tester->execute(['name' => 'durable']);

        $dump = preg_replace('/^(\s+enabled:\s+)false$/m', "\$1'%kernel.debug%'", $tester->getDisplay(true));

        return implode("\n", array_map(rtrim(...), explode("\n", rtrim((string) $dump)))) . "\n";
    }

    /**
     * `## \`key\`` sections of the page, by key.
     *
     * @return array<string, string>
     */
    private static function sections(string $markdown): array
    {
        preg_match_all('/^## `([a-z0-9_]+)`\n(.*?)(?=^## |\z)/ms', $markdown, $matches, \PREG_SET_ORDER);

        return array_column(array_map(static fn(array $m): array => [$m[1], $m[2]], $matches), 1, 0);
    }

    /**
     * @param array<mixed> $node
     *
     * @return list<string>
     */
    private static function leaves(array $node, string $prefix = ''): array
    {
        $leaves = [];
        foreach ($node as $key => $value) {
            $path = $prefix . $key;
            if (\is_array($value) && [] !== $value && !array_is_list($value)) {
                $leaves = [...$leaves, ...self::leaves($value, $path . '.')];
            } else {
                $leaves[] = $path;
            }
        }

        return $leaves;
    }
}
