<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Queue;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Une reprise à la fois par exécution.
 *
 * C'est la seule chose que le stockage ne peut pas fournir, et sans elle rien de ce paquet ne tient
 * : deux workers qui reprennent la **même** exécution la rejouent tous les deux, chacun croit
 * découvrir les commandes qu'elle produit, et elles partent en double. Le journal ne l'empêche pas
 * — il enregistre fidèlement ce qu'on lui donne, y compris deux fois. Le docblock de
 * `DbalEventStore` le dit depuis toujours ; côté Symfony c'est un middleware Messenger adossé à
 * `symfony/lock`, ici c'est le verrou atomique du cache.
 *
 * **Pourquoi une fermeture plutôt qu'un middleware de job.** Un middleware Laravel s'accroche à une
 * classe de job, et ce paquet n'en fournit aucune : ce sont des magasins. Le paquet d'intégration
 * en aura, et il lui suffira d'envelopper son `handle()` avec ceci. Une commande artisan ou un
 * worker écrit à la main y arrivent aussi, sans rien avoir à hériter.
 *
 * **Pourquoi `LockProvider` et pas le cache.** C'est le seul contrat dont ce verrou a besoin, il
 * vient d'`illuminate/contracts` que `illuminate/database` tire déjà, et il oblige l'appelant à
 * choisir un store **qui sait verrouiller** : `array`, `redis`, `memcached`, `dynamodb`,
 * `database`. Le store `file` n'implémente pas `LockProvider`, et c'est une bonne nouvelle qu'il ne
 * compile pas plutôt qu'un verrou qui ne verrouille rien.
 *
 * ```php
 * $lock->around($executionId, fn() => $runner->resume($executionId));
 * ```
 *
 * **Pourquoi l'attente est écrite ici plutôt que déléguée à `Lock::block()`.** `block()` appelle un
 * `now()` **global**, que seule une application Laravel complète définit — `illuminate/support` ne
 * le publie que sous son propre espace de noms. Un paquet qui s'en sert marche dans une application
 * et casse dans un worker autonome ou un test, ce qui est le pire des deux mondes : la panne
 * n'arrive que là où personne ne regarde. Huit lignes d'attente bornée n'ont pas cette dépendance.
 *
 * ponytail: sondage toutes les 100 ms plutôt qu'une notification. Un verrou de reprise se prend
 * pour la durée d'un pas de workflow ; si un jour la contention le justifie, Redis sait notifier.
 *
 * ponytail: attente bornée par `$waitSeconds`. Un worker qui attend son tour est ce qu'on
 * veut ; un worker qui attend indéfiniment sur un verrou qu'un processus mort n'a jamais relâché ne
 * l'est pas — d'où le TTL, qui est le vrai filet.
 *
 * @see \Gplanchat\Bridge\Dbal\Messenger\SingleResumeLockMiddleware le pendant Symfony
 */
final class ResumeLock
{
    /** Entre deux tentatives : assez court pour ne pas retarder, assez long pour ne pas brûler. */
    private const POLL_MICROSECONDS = 100_000;

    public function __construct(
        private readonly LockProvider $locks,
        /** Durée de vie du verrou : ce qui le libère si le processus qui le tient meurt. */
        private readonly int $ttlSeconds = 300,
        /** Combien de temps un worker accepte d'attendre son tour avant d'abandonner. */
        private readonly int $waitSeconds = 10,
    ) {}

    /**
     * Exécute `$work` en tenant le verrou de cette exécution, et le relâche quoi qu'il arrive.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException si le tour n'est pas venu à temps
     */
    public function around(string $executionId, callable $work): mixed
    {
        $lock = $this->locks->lock(self::nameFor($executionId), $this->ttlSeconds);
        $deadline = microtime(true) + $this->waitSeconds;

        while (true) {
            if ($lock->get()) {
                try {
                    return $work();
                } finally {
                    $lock->release();
                }
            }

            if (microtime(true) >= $deadline) {
                throw new LockTimeoutException(\sprintf(
                    'Another worker is already resuming %s.',
                    $executionId,
                ));
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Le nom du verrou d'une exécution.
     *
     * Exposé parce qu'un appelant peut vouloir le poser lui-même — une commande qui reprend une
     * exécution à la main doit prendre **le même** verrou que le worker, et deviner son nom est la
     * façon dont deux processus finissent par croire qu'ils s'excluent alors que non.
     */
    public static function nameFor(string $executionId): string
    {
        return 'durable-resume-' . $executionId;
    }
}
