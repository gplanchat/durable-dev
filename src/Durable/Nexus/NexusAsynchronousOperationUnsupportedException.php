<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

/**
 * L'endpoint a démarré l'opération de façon **asynchrone** : il la complétera plus tard, par un
 * autre canal, et l'a signalé en posant un jeton sur `NEXUS_OPERATION_STARTED`.
 *
 * Cet incrément ne sait pas suivre une opération asynchrone. Le taire ferait attendre le workflow
 * sans fin, sans rien dans les journaux — le mode d'échec que ce composant refuse partout ailleurs.
 * Il vaut mieux une exécution qui échoue en nommant la limite.
 */
final class NexusAsynchronousOperationUnsupportedException extends \RuntimeException
{
    public static function forOperation(string $operationId, string $operationName): self
    {
        return new self(\sprintf(
            'Nexus operation "%s" (%s) was started asynchronously: the endpoint returned an operation token '
            . 'and will complete it later, out of band. This increment only supports operations that complete '
            . 'in the same exchange — see temporal-nexus-support §4.5.',
            $operationName,
            $operationId,
        ));
    }
}
