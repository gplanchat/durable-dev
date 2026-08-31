<?php

declare(strict_types=1);

namespace App\Ai\Durable;

use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use App\Ai\Tool\ToolDefinition;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Le toolbox n'est plus qu'un **registre de schémas** : l'exécution appartient à
 * {@see DurableToolExecutor}, donc à des activités.
 *
 * Les schémas sont figés à la construction du workflow et voyagent dans le payload de l'activité.
 * Les relire d'un conteneur DI au rejeu ferait diverger `Runner::exposeTools()`, qui les injecte
 * dans `$options['tools']` à chaque tour.
 */
final class SchemaOnlyToolbox implements ToolboxInterface
{
    /** @var Tool[] */
    private readonly array $tools;

    /**
     * @param list<ToolDefinition> $definitions
     */
    public function __construct(array $definitions)
    {
        $this->tools = array_map(
            static fn (ToolDefinition $definition): Tool => new Tool(
                new ExecutionReference(DurableToolExecutor::class, 'execute'),
                $definition->name,
                $definition->description,
                $definition->parameters,
            ),
            $definitions,
        );
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        // `Runner` passe par le ToolExecutor, jamais par ici. Si ce chemin s'ouvre un jour, il
        // exécuterait l'outil en code workflow — hors journal, donc rejoué à chaque reprise.
        throw new \LogicException(\sprintf('L\'exécution appartient à %s, pas au toolbox.', DurableToolExecutor::class));
    }
}
