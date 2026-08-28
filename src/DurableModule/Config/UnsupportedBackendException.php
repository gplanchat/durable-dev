<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Config;

/**
 * Le backend demandé n'est pas atteignable depuis Magento, ou n'existe pas.
 *
 * Le refus est nommé et immédiat, comme celui de {@see \Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException} :
 * une configuration acceptée puis silencieusement ignorée laisse un workflow attendre un journal
 * que personne n'écrit, et l'exploitant cherche la panne partout sauf dans sa configuration.
 *
 * Les deux cas ne disent pas la même chose. Un pont SQL est un vrai backend de Durable, refusé
 * ici pour une raison d'hôte — il faut donc la donner, sans quoi on relit sa configuration en
 * cherchant une faute de frappe qui n'y est pas. Un nom inconnu, lui, appelle la liste.
 */
final class UnsupportedBackendException extends \RuntimeException
{
    /**
     * @param non-empty-list<string> $reachable
     */
    public static function sqlBridge(string $backend, array $reachable): self
    {
        return new self(\sprintf(
            'The %s backend cannot run on Magento: it binds to a connection type Magento does not ship, so its journal would have nothing to write to. This host reaches %s — the state lives in a Temporal cluster, or it lives in one process.',
            $backend,
            \implode(' and ', $reachable),
        ));
    }

    /**
     * Le backend est atteignable depuis Magento, mais ce module ne le câble pas encore.
     *
     * Le dire vaut mieux que de servir l'autre en silence : une configuration qui demande Temporal
     * et reçoit un journal en mémoire perd tout à la sortie du processus, et rien ne l'annonce.
     */
    public static function notWiredYet(string $backend, string $servedInstead): self
    {
        return new self(\sprintf(
            'The %s backend is not wired in this module yet; this process only assembles the %s one. Configure durable/backend accordingly, or wait for the module to carry it.',
            $backend,
            $servedInstead,
        ));
    }

    /**
     * @param non-empty-list<string> $reachable
     */
    public static function unknown(string $backend, array $reachable): self
    {
        return new self(\sprintf(
            'There is no %s backend. Magento reaches %s.',
            $backend,
            \implode(' and ', $reachable),
        ));
    }
}
