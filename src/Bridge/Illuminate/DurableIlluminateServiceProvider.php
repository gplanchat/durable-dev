<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate;

use Illuminate\Support\ServiceProvider;

/**
 * Publie les migrations du pont, et rien d'autre.
 *
 * Ce n'est **pas** le service provider de l'intégration Laravel : il n'enregistre aucun magasin, ne
 * lie aucune interface, n'ajoute aucune commande. Un jeu de magasins ne décide pas comment une
 * application les câble — ça, c'est le travail du paquet d'intégration, et le README le dit depuis
 * le premier jour.
 *
 * Ce qu'il fait est ce qu'aucun autre paquet ne peut faire à sa place : dire à Laravel où sont
 * **ses** migrations. Sans lui, chaque application les copierait à la main, et une correction de
 * schéma ne remonterait jamais aux applications déjà installées.
 *
 * ```bash
 * php artisan vendor:publish --tag=durable-migrations
 * php artisan migrate
 * ```
 *
 * Publier n'est pas obligatoire : les migrations sont déjà chargées depuis le paquet, donc
 * `php artisan migrate` suffit. On publie quand on veut les modifier — et à partir de là, elles
 * appartiennent à l'application.
 */
final class DurableIlluminateServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $migrations = __DIR__ . '/Migrations';

        $this->loadMigrationsFrom($migrations);

        if ($this->app->runningInConsole()) {
            $this->publishes(
                [$migrations => $this->app->databasePath('migrations')],
                'durable-migrations',
            );
        }
    }
}
