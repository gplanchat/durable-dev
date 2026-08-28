<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Attribute;

/**
 * Déclare qu'un service sert une opération Nexus.
 *
 * Les deux noms ne sont pas décoratifs : une tâche entrante n'est routée que par le couple
 * (service, opération), et rien d'autre ne l'identifie. Une faute de frappe dans l'un des deux
 * donne un gestionnaire que rien n'atteint jamais — c'est pourquoi la passe de compilation les
 * valide au démarrage plutôt qu'à la première tâche.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class AsNexusOperationHandler
{
    public function __construct(
        public string $service,
        public string $operation,
        public string $method = '__invoke',
    ) {}
}
