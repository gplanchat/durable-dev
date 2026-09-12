<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * L'outil que la garde protège : un envoi ne se compense pas d'un clic. En mode `standard` comme en
 * `edition`, il demande un accord humain — et le workflow reste suspendu jusque-là.
 */
#[AutoconfigureTag('app.ai.tool', ['key' => 'send_email'])]
final class SendEmailTool
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments): string
    {
        return \sprintf('Courriel envoyé à %s.', (string) ($arguments['to'] ?? '?'));
    }
}
