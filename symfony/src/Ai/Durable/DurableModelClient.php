<?php

declare(strict_types=1);

namespace App\Ai\Durable;

use App\Ai\Activity\ModelInvocationActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\WorkflowEnvironment;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use App\Ai\Platform\JournaledHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;

/**
 * La couture basse de Symfony AI : `Provider` a déjà normalisé la conversation et les schémas
 * d'outils en tableaux, il ne reste que l'appel HTTP — qu'on remplace par une activité.
 *
 * Conséquence : `Runner::run()` devient du code workflow ordinaire, rejoué, dont la seule jambe non
 * déterministe sort du journal.
 */
final class DurableModelClient implements ModelClientInterface
{
    private readonly ActivityStub $stub;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
        ?ActivityOptions $options = null,
    ) {
        $this->stub = $environment->activityStub(ModelInvocationActivityInterface::class, $options);
    }

    public function supports(Model $model): bool
    {
        return true;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        if (!\is_array($payload)) {
            throw new \InvalidArgumentException('Un payload textuel ne traverse pas la frontière d\'activité de ce prototype.');
        }

        if (true === ($options['stream'] ?? false)) {
            // Une activité rend une valeur une fois : un flux de deltas ne se rejoue pas.
            throw new \LogicException('Le streaming est incompatible avec le rejeu : journalise le résultat assemblé, streame sur un canal latéral.');
        }

        return new JournaledHttpResult(
            $this->environment->await($this->stub->invokeModel($model->getName(), $payload, $options)),
        );
    }
}
