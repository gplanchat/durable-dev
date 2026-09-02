<?php

declare(strict_types=1);

namespace App\Ai\Live;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Dit au navigateur qu'une exécution a bougé. Rien de plus.
 *
 * **Une sonnette, pas un colis.** Le fil publié serait la projection du journal calculée une fois
 * par abonné, dans un worker qui n'a rien à faire de l'affichage — et un second endroit où
 * {@see \App\Ai\Chat\ChatTranscript::forExecution()} vivrait. La page sait déjà aller le chercher,
 * et l'ETag rend gratuit le cas où rien n'a changé.
 *
 * **D'où l'on sonne : du contrôleur, et de nulle part ailleurs.** Deux autres crochets ont été
 * essayés et écartés, tous deux parce que la démo tourne sur Temporal :
 *
 * - `EventStoreInterface::append()` — le cycle de vie du magasin ne se déclenche pas sur une
 *   application Temporal, {@see \Gplanchat\Durable\Store\ProjectingEventStore} le dit lui-même ;
 * - `WorkerMessageHandledEvent` — les transports Temporal font le travail *dans* leur `get()` et
 *   ne rendent **aucune enveloppe** ({@see \Gplanchat\Bridge\Temporal\Messenger\TemporalJournalTransport}).
 *   Il n'y a donc pas de message manipulé, donc pas d'événement.
 *
 * Reste le contrôleur, où chaque action humaine passe. Faire porter l'identifiant d'exécution aux
 * activités de l'agent le rendrait possible côté machine — mais ce serait changer un contrat
 * d'activité pour de l'affichage, et ça n'apporterait rien : pendant qu'un tour est en cours, la
 * page sonde déjà à 700 ms parce qu'elle se sait en attente.
 *
 * **Ce que la sonnerie couvre**, donc : ce qui arrive *du dehors* de cette page — un message envoyé
 * depuis un autre onglet, une décision prise ailleurs. **Ce qu'elle ne couvre pas**, et pourquoi le
 * sondage lent reste : une échéance qui tombe, une clôture sur inactivité, un relais. Mercure pour
 * ce qui vient d'ailleurs, le sondage pour l'exhaustivité.
 */
final readonly class AgentLiveFeed
{
    public function __construct(
        private HubInterface $hub,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public static function topicFor(string $executionId): string
    {
        return 'durable/agent/' . $executionId;
    }

    /**
     * Une sonnerie perdue n'est pas une panne : le fil se rattrape au sondage suivant. On ne
     * laisse donc jamais l'indisponibilité du concentrateur casser un signal ou une activité —
     * ce serait échanger un rafraîchissement contre une exécution.
     */
    public function nudge(string $executionId): void
    {
        try {
            $this->hub->publish(new Update(
                self::topicFor($executionId),
                json_encode(['executionId' => $executionId], \JSON_THROW_ON_ERROR),
            ));
        } catch (\Throwable $exception) {
            $this->logger?->debug('Le concentrateur Mercure n\'a pas pris la sonnerie : {message}', [
                'message' => $exception->getMessage(),
                'executionId' => $executionId,
            ]);
        }
    }
}
