<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Messenger;

use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Une seule reprise à la fois par exécution.
 *
 * Temporal sérialise les tâches d'un même workflow côté serveur ; le backend DBAL n'a pas de
 * serveur, donc deux consumers qui dépilent deux reprises de la même exécution rejoueraient le
 * même fiber en parallèle et écriraient deux fois les mêmes commandes — activités dupliquées,
 * journal divergent. Ce verrou est la contrepartie de cette absence.
 *
 * ponytail: acquisition bloquante — le worker attend son tour plutôt que de renvoyer le message.
 * Passer à un rejet + retry Messenger si l'attente occupe trop de workers.
 *
 * @see DUR030
 */
final class SingleResumeLockMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly float $ttlSeconds = 300.0,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $executionId = self::executionIdOf($envelope->getMessage());
        if (null === $executionId) {
            return $stack->next()->handle($envelope, $stack);
        }

        $lock = $this->lockFactory->createLock('durable-resume-'.$executionId, $this->ttlSeconds);
        $lock->acquire(true);

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $lock->release();
        }
    }

    private static function executionIdOf(object $message): ?string
    {
        return match (true) {
            $message instanceof ResumeWorkflowMessage,
            $message instanceof FireWorkflowTimersMessage => $message->executionId,
            default => null,
        };
    }
}
