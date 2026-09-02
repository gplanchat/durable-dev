<?php

declare(strict_types=1);

namespace App\Ai\Watch;

use App\Ai\Guard\ToolEffect;
use App\Ai\Tool\ToolDefinition;

/**
 * L'outil par lequel l'agent se met en veille.
 *
 * Comme `demander_a_l_utilisateur`, son exécution est une suspension — mais ce qui la lève ne
 * vient pas d'un humain devant une carte : c'est un événement du dehors, une supervision, un
 * webhook, un autre agent.
 *
 * Ce qu'un processus ne sait pas faire : la veille est **journalisée**. L'agent peut dormir trois
 * jours, traverser un redéploiement, et retrouver au réveil non seulement l'observation mais
 * **l'intention qu'il avait écrite en s'inscrivant**. Rien à se rappeler, tout est au journal.
 *
 * Le `sujet` est ce qui rend la veille joignable : {@see WatchSubject} en tient le vocabulaire, et
 * c'est lui — pas l'`observation`, qui est pour l'humain — qu'un événement métier appariera.
 *
 * Classé `read` : se mettre en veille n'écrit nulle part. Ce que l'agent fera *ensuite* repassera
 * par la garde comme n'importe quel appel.
 */
final class WatchTool
{
    public const TOOL = 'surveiller';

    private function __construct()
    {
    }

    private static function catalogue(): string
    {
        return implode(', ', array_map(
            static fn (WatchSubject $s): string => \sprintf('`%s` (%s)', $s->value, $s->describe()),
            WatchSubject::cases(),
        ));
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            self::TOOL,
            'Se met en veille et attend qu’un événement extérieur survienne. À utiliser quand la '
            .'suite dépend de quelque chose qui n’a pas encore eu lieu — une livraison, un retour '
            .'de fournisseur, un seuil franchi. L’exécution reprendra à l’alerte, même des jours '
            .'plus tard.',
            ToolEffect::Read,
            [
                'type' => 'object',
                'properties' => [
                    'sujet' => [
                        'type' => 'string',
                        'enum' => WatchSubject::values(),
                        'description' => 'L’événement que tu attends, à choisir dans la liste : '
                            .self::catalogue()
                            .'. C’est lui, et pas ta phrase, qui réveillera la veille — un sujet '
                            .'hors liste sera refusé.',
                    ],
                    'observation' => [
                        'type' => 'string',
                        'description' => 'Ce que tu guettes, dit en une phrase lisible par un humain.',
                    ],
                    'intention' => [
                        'type' => 'string',
                        'description' => 'Ce que tu feras quand l’alerte arrivera. Écris-le maintenant : '
                            .'c’est ce qu’on te rendra au réveil, tu n’auras pas à t’en souvenir.',
                    ],
                    'deadlineSeconds' => [
                        'type' => 'number',
                        'description' => 'Au-delà de ce délai, la veille est abandonnée et tu reprends la main.',
                    ],
                ],
                'required' => ['sujet', 'observation', 'intention'],
            ],
        );
    }
}
