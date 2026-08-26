<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

/**
 * Une opération Nexus s'est terminée autrement que par un succès.
 *
 * Les quatre issues ne sont pas un découpage de confort : ce sont les quatre événements terminaux
 * que le serveur déclare — `NEXUS_OPERATION_COMPLETED`, `..._FAILED`, `..._CANCELED`,
 * `..._TIMED_OUT` — et il porte deux infos d'échec distinctes, `NexusOperationFailureInfo` et
 * `NexusHandlerFailureInfo`. Les aplatir ferait perdre au workflow ce qu'il doit savoir pour
 * compenser : une opération refusée se rejoue rarement comme un endpoint tombé.
 *
 * L'origine est aussi ce qui manque le plus à la relecture d'un journal raté, d'où sa
 * classification propre ({@see \Gplanchat\Durable\Event\WorkflowExecutionFailed::KIND_NEXUS_OPERATION}).
 */
final class DurableNexusOperationFailedException extends \RuntimeException
{
    /** L'opération elle-même a échoué : l'endpoint a répondu, et c'est un refus. */
    public const KIND_OPERATION_FAILED = 'operation_failed';

    /** Celui qui sert l'opération a échoué : la réponse n'est jamais venue de l'opération. */
    public const KIND_HANDLER_FAILED = 'handler_failed';

    /** Une des bornes de l'opération s'est écoulée. */
    public const KIND_TIMED_OUT = 'timed_out';

    /** L'opération a été annulée, par le workflow ou par sa clôture. */
    public const KIND_CANCELLED = 'cancelled';

    private function __construct(
        private readonly string $operationId,
        private readonly string $operationName,
        private readonly string $kind,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function operationFailed(string $operationId, string $operationName, string $reason, ?\Throwable $previous = null): self
    {
        return new self($operationId, $operationName, self::KIND_OPERATION_FAILED, \sprintf(
            'Nexus operation "%s" (%s) failed: %s',
            $operationName,
            $operationId,
            $reason,
        ), $previous);
    }

    public static function handlerFailed(string $operationId, string $operationName, string $reason, ?\Throwable $previous = null): self
    {
        return new self($operationId, $operationName, self::KIND_HANDLER_FAILED, \sprintf(
            'The handler serving Nexus operation "%s" (%s) failed: %s',
            $operationName,
            $operationId,
            $reason,
        ), $previous);
    }

    public static function timedOut(string $operationId, string $operationName): self
    {
        return new self($operationId, $operationName, self::KIND_TIMED_OUT, \sprintf(
            'Nexus operation "%s" (%s) timed out',
            $operationName,
            $operationId,
        ));
    }

    public static function cancelled(string $operationId, string $operationName, string $reason): self
    {
        return new self($operationId, $operationName, self::KIND_CANCELLED, \sprintf(
            'Nexus operation "%s" (%s) was cancelled (%s)',
            $operationName,
            $operationId,
            $reason,
        ));
    }

    public function operationId(): string
    {
        return $this->operationId;
    }

    public function operationName(): string
    {
        return $this->operationName;
    }

    /** L'une des quatre constantes `KIND_*`. */
    public function kind(): string
    {
        return $this->kind;
    }
}
