<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un outil d'agent est un effet de bord : il s'exécute dans une activité, jamais en code workflow.
 */
#[AutoconfigureTag('app.ai.tool', ['key' => 'weather'])]
final class WeatherTool
{
    private const RELEVES = ['Paris' => '22°C, ensoleillé', 'Lyon' => '25°C, nuageux'];

    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments): string
    {
        $city = (string) ($arguments['city'] ?? '');

        return \sprintf('%s : %s', $city, self::RELEVES[$city] ?? 'relevé indisponible');
    }
}
