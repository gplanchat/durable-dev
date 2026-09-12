<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.ai.tool', ['key' => 'save_note'])]
final class SaveNoteTool
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments): string
    {
        return \sprintf('Note enregistrée (%d caractères).', \strlen((string) ($arguments['text'] ?? '')));
    }
}
