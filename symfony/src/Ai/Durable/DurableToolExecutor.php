<?php

declare(strict_types=1);

namespace App\Ai\Durable;

use App\Ai\Activity\AgentToolActivityInterface;
use App\Ai\Guard\AgentMode;
use App\Ai\Guard\ToolApprovalGate;
use App\Ai\Guard\ToolGuardInterface;
use App\Ai\Guard\ToolVerdict;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\DeadlineExceededException;
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

    /**
     * @param \Closure(): AgentMode $mode            le mode est de l'état de workflow, il peut changer
     *                                               entre deux tours — donc il se lit au moment de la décision
     * @param Duration|null         $approvalTimeout échéance d'une demande de validation, globale à
     *                                               l'instance d'agent ; `null` = attendre indéfiniment
     */
    public function __construct(
        private readonly WorkflowEnvironment $environment,
        private readonly ToolGuardInterface $guard,
        private readonly ToolApprovalGate $gate,
        private readonly \Closure $mode,
        private readonly ?Duration $approvalTimeout = null,
        ?ActivityOptions $options = null,
    ) {
        $this->stub = $environment->activityStub(AgentToolActivityInterface::class, $options);
    }

    public function execute(array $toolCalls): \Generator
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $decision = $this->guard->decide($toolCall, ($this->mode)());

            if (ToolVerdict::Deny === $decision->verdict) {
                yield new Progress('tool_denied', (string) $decision->reason, $toolCall);
                $results[] = new ToolResult($toolCall, \sprintf('Refusé : %s', $decision->reason));

                continue;
            }

            if (ToolVerdict::Ask === $decision->verdict) {
                $this->gate->ask($toolCall, (string) $decision->reason);
                yield new Progress('tool_approval', (string) $decision->reason, $toolCall);

                // Suspension, pas attente : le processus peut mourir ici, l'accord peut arriver
                // demain, le workflow reprendra à cette ligne. L'échéance est un minuteur du
                // journal (DUR032), donc elle survit au redémarrage elle aussi.
                try {
                    $this->environment->await(
                        fn(): bool => $this->gate->isDecided($toolCall->getId()),
                        $this->approvalTimeout,
                    );
                } catch (DeadlineExceededException) {
                    // Pas de réponse vaut refus. La décision est inscrite dans la porte pour que le
                    // rejeu la relise au lieu de replanifier un minuteur déjà tiré.
                    $this->gate->decide($toolCall->getId(), false);
                    yield new Progress('tool_expired', \sprintf('Validation de « %s » expirée.', $toolCall->getName()), $toolCall);
                    $results[] = new ToolResult($toolCall, 'Refusé : aucune validation reçue avant l\'échéance.');

                    continue;
                }

                if (!$this->gate->isApproved($toolCall->getId())) {
                    $results[] = new ToolResult($toolCall, 'Refusé par l\'utilisateur.');

                    continue;
                }
            }

            yield new Progress('tool_call', \sprintf('Exécution de l\'outil "%s".', $toolCall->getName()), $toolCall);

            $results[] = new ToolResult(
                $toolCall,
                $this->environment->await($this->stub->callTool($toolCall->getId(), $toolCall->getName(), $toolCall->getArguments())),
            );
        }

        return $results;
    }
}
