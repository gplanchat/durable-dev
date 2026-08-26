<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * L'update a échoué, pas l'exécution.
 *
 * C'est ce qui distingue un update du corps d'un workflow : il a un appelant, et une défaillance
 * de son handler lui revient à lui seul. Le workflow, lui, n'a rien vu passer et poursuit.
 *
 * Sans ce type, l'échec revenait comme `null` — indiscernable d'un handler qui rend
 * légitimement `null`, la même ambiguïté que DUR032 a levée pour les échéances.
 */
final class WorkflowUpdateFailedException extends \RuntimeException
{
    public function __construct(
        private readonly string $updateName,
        string $message,
    ) {
        parent::__construct(\sprintf('Update "%s" failed: %s', $updateName, $message));
    }

    public function updateName(): string
    {
        return $this->updateName;
    }
}
