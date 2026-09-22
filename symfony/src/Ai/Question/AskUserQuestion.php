<?php

declare(strict_types=1);

namespace App\Ai\Question;

use App\Ai\Guard\ToolEffect;
use App\Ai\Tool\ToolDefinition;

/**
 * L'outil par lequel l'agent pose une question à l'humain, façon questionnaire.
 *
 * Il n'a pas d'activité derrière lui : son résultat n'est pas calculé, il est *attendu*. C'est le
 * seul outil dont l'exécution est une suspension.
 *
 * Toujours offert à l'agent — un agent qui ne peut pas demander invente. Classé `read` : demander
 * ne casse rien, la garde n'a donc pas à s'y opposer, et personne ne devrait avoir à autoriser une
 * question avant d'y répondre.
 */
final class AskUserQuestion
{
    public const TOOL = 'demander_a_l_utilisateur';

    private function __construct()
    {
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            self::TOOL,
            'Pose une question à l’utilisateur et attend sa réponse. À utiliser quand une décision '
            .'lui appartient — un choix entre plusieurs suites possibles, une préférence, une '
            .'information que rien ne permet de deviner. Ne sert pas à demander la permission '
            .'d’exécuter un outil : cela se fait tout seul.',
            ToolEffect::Read,
            [
                'type' => 'object',
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'description' => 'La question, formulée pour être lue telle quelle.',
                    ],
                    'header' => [
                        'type' => 'string',
                        'description' => 'Étiquette courte qui dit de quoi il s’agit (quelques mots).',
                    ],
                    'options' => [
                        'type' => 'array',
                        'description' => 'Les suites possibles. Deux à quatre, distinctes, la recommandée en premier.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => ['type' => 'string', 'description' => 'Le choix, en quelques mots.'],
                                'description' => ['type' => 'string', 'description' => 'Ce que ce choix implique.'],
                            ],
                            'required' => ['label'],
                        ],
                    ],
                    'multiSelect' => [
                        'type' => 'boolean',
                        'description' => 'Vrai si plusieurs réponses peuvent être retenues ensemble.',
                    ],
                ],
                'required' => ['question', 'options'],
            ],
        );
    }
}
