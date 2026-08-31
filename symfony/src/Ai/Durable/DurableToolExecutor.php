<?php

declare(strict_types=1);

namespace App\Ai\Durable;

use App\Ai\Activity\AgentToolActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\WorkflowEnvironment;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Toolbox\ToolExecutorInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;

/**
 * `Runner` délègue ici par `yield from` ; le `Fiber::suspend()` de {@see WorkflowEnvironment::await()}
 * traverse la délégation de générateur sans rien casser.
 *
 * ponytail: exécution séquentielle. `$environment->all(...)` paralléliserait les appels d'un même
 * tour — à faire quand un tour a réellement plusieurs outils lents.
 */
final class DurableToolExecutor implements ToolExecutorInterface
{
    private readonly ActivityStub $stub;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
        ?ActivityOptions $options = null,
    ) {
        $this->stub = $environment->activityStub(AgentToolActivityInterface::class, $options);
    }

    public function execute(array $toolCalls): \Generator
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            yield new Progress('tool_call', \sprintf('Exécution de l\'outil "%s".', $toolCall->getName()), $toolCall);

            $results[] = new ToolResult(
                $toolCall,
                $this->environment->await($this->stub->callTool($toolCall->getName(), $toolCall->getArguments())),
            );
        }

        return $results;
    }
}
