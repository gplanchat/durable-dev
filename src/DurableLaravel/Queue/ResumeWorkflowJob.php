<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Bridge\Illuminate\Queue\ResumeLock;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Une reprise de workflow, et une seule à la fois par exécution.
 *
 * Deux workers qui reprennent la **même** exécution la rejouent tous les deux, chacun croit
 * découvrir les commandes qu'elle produit, et ces commandes partent en double. Le journal ne
 * l'empêche pas : il enregistre fidèlement ce qu'on lui donne, deux fois comprises.
 *
 * **Ce job ne se remet pas en file, il en redispatche un autre — et c'est délibéré.**
 * `$this->release()` demande le trait `InteractsWithQueue`, donc `illuminate/queue`, donc
 * `symfony/process ^7.2` : le paquet deviendrait irréconciliable avec la ligne Symfony 6.4 que la
 * matrice du dépôt teste encore. Mais l'argument n'est pas seulement d'emballage — §1.2 a mesuré
 * que `release()` **consomme un essai**, si bien qu'à `--tries=5`, quinze reprises sur vingt
 * finissaient dans `failed_jobs` sans avoir tourné une seule fois : la contention y devenait
 * indiscernable d'un bug. Un job neuf repart avec un budget d'essais neuf, et `tries` retrouve son
 * sens — le nombre de fois qu'un plantage est toléré.
 *
 * Le prix, et il est réel : rien ne borne plus le report côté file. C'est `$deferrals` qui le
 * borne ici, et le dépassement est bruyant.
 */
final class ResumeWorkflowJob implements ShouldQueue
{
    public function __construct(
        public readonly ResumeWorkflowMessage $message,
        /** Combien de fois cette reprise a déjà trouvé le tour pris. */
        public readonly int $deferrals = 0,
    ) {}

    public function handle(
        ResumeWorkflowHandler $handler,
        ResumeLock $lock,
        QueueFactory $queue,
        ResumeDeferral $deferral,
    ): void {
        $replayed = $lock->tryAround($this->message->executionId, function () use ($handler): void {
            $handler($this->message);
        });

        if ($replayed) {
            return;
        }

        // Le tour était pris : un autre worker rejoue cette exécution en ce moment même.
        $deferral->defer($this, $queue);
    }
}
