<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\Command\DiagnoseExecutionCommand;
use Gplanchat\Durable\Bundle\DependencyInjection\Configuration;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\NodeInterface;

/**
 * `bin/console list` and `bin/console config:dump-reference` are read by whoever installs the
 * bundle, which makes them shipped text under WA006 just as much as the profiler panel is.
 *
 * Both surfaces are reachable without a container: a command carries its own definition, and a
 * configuration tree carries the `info()` of every node.
 */
final class TheConsoleSurfaceSpeaksEnglishTest extends TestCase
{
    private const ACCENTED = '/[àâäçéèêëîïôöùûüœÀÂÄÇÉÈÊËÎÏÔÖÙÛÜŒ]/u';

    public function testTheDiagnoseCommandDescribesItselfInEnglish(): void
    {
        $command = new DiagnoseExecutionCommand(
            new InMemoryWorkflowMetadataStore(),
            new InMemoryEventStore(),
            new InMemoryChildWorkflowParentLinkStore(),
        );
        $definition = $command->getDefinition();

        $described = [$command->getDescription(), $command->getHelp()];
        foreach ($definition->getArguments() as $argument) {
            $described[] = $argument->getDescription();
        }
        foreach ($definition->getOptions() as $option) {
            $described[] = $option->getDescription();
        }

        self::assertSame([], self::french($described));
    }

    public function testTheConfigurationReferenceIsWrittenInEnglish(): void
    {
        $tree = (new Configuration())->getConfigTreeBuilder()->buildTree();

        self::assertSame([], self::french(self::infos($tree)));
    }

    /**
     * @return list<string>
     */
    private static function infos(NodeInterface $node): array
    {
        $infos = method_exists($node, 'getInfo') ? [(string) $node->getInfo()] : [];

        if ($node instanceof ArrayNode) {
            foreach ($node->getChildren() as $child) {
                $infos = array_merge($infos, self::infos($child));
            }
        }

        return $infos;
    }

    /**
     * @param list<string> $texts
     *
     * @return list<string>
     */
    private static function french(array $texts): array
    {
        return array_values(array_filter($texts, static fn (string $t): bool => 1 === preg_match(self::ACCENTED, $t)));
    }
}
