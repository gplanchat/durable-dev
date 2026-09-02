<?php

declare(strict_types=1);

namespace App\Ai\Team;

use App\Ai\Guard\ToolEffect;
use App\Ai\Tool\ToolDefinition;

/**
 * L'outil par lequel un agent en fait travailler un autre.
 *
 * Un sous-agent est un **workflow enfant** : sa propre exécution, son propre journal, son propre
 * modèle. C'est ce qui permet d'assembler une équipe où chacun a le modèle qui lui va — un petit
 * pour trier, un gros pour rédiger — sans que le parent ait à savoir comment l'autre est fait.
 *
 * **Ce que déléguer ne donne pas : de l'autorité.** Le délégué hérite du mode effectif de son
 * parent comme plafond ({@see \App\Ai\Guard\AgentMode::strictest()}), et rien ne le desserre.
 * Sinon un agent en `standard` confierait à un sous-agent en `auto` ce que sa garde lui refuse, et
 * la garde ne serait plus qu'une décoration.
 *
 * Conséquence assumée, et c'est ce qui rend la chose sûre sans règle en plus : sous un plafond
 * `standard`, un sous-agent ne peut faire que des lectures — donc rien qui demande une approbation
 * que personne n'est là pour lui donner. Personne ne regarde un sous-agent ; il n'a donc le droit
 * de rien faire d'irréversible, à moins qu'un humain n'ait explicitement mis la chaîne en `auto`.
 *
 * Classé `read` : déléguer n'écrit nulle part. Ce que le délégué fera, lui, repasse par sa propre
 * garde, sous le plafond hérité.
 */
final class DelegateTool
{
    public const TOOL = 'deleguer';

    private function __construct()
    {
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            self::TOOL,
            'Confie une mission à un sous-agent et attend sa réponse. À utiliser quand la tâche '
            .'gagne à être traitée à part — un autre modèle, un contexte propre, un raisonnement '
            .'qui n’a pas à encombrer le tien. Le sous-agent ne peut jamais faire plus que ce que '
            .'ta propre garde t’autorise.',
            ToolEffect::Read,
            [
                'type' => 'object',
                'properties' => [
                    'mission' => [
                        'type' => 'string',
                        'description' => 'Ce que le sous-agent doit faire, en entier : il ne voit '
                            .'rien de votre conversation, seulement cette phrase.',
                    ],
                    'modele' => [
                        'type' => 'string',
                        'description' => 'Le modèle à lui confier. À omettre pour reprendre le tien.',
                    ],
                ],
                'required' => ['mission'],
            ],
        );
    }
}
