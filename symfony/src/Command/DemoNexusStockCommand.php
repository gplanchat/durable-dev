<?php

declare(strict_types=1);

namespace App\Command;

use App\Durable\Workflow\ReserverStockWorkflow;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Démarre l'appelant de la démonstration : le métier demande du stock à la boutique.
 *
 * Elle ne parle pas à la boutique. Elle démarre un workflow dans `demo-metier`, et c'est ce
 * workflow qui appelle l'opération Nexus — le seul lien entre les deux applications est l'endpoint,
 * créé par `bin/demo-nexus`, et le contrat qu'elles se partagent.
 */
#[AsCommand(
    name: 'durable:demo:nexus',
    description: 'Demande à la boutique de retenir du stock, à travers Nexus',
)]
final class DemoNexusStockCommand extends Command
{
    public function __construct(
        private readonly WorkflowClientInterface $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('commande', InputArgument::REQUIRED, 'Identifiant de commande — c\'est lui qui rend la réservation idempotente')
            ->addArgument('lignes', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'REFERENCE=quantité, une ou plusieurs')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Secondes d\'attente du verdict', '60')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $commande = (string) $input->getArgument('commande');
        $lignes = [];
        foreach ((array) $input->getArgument('lignes') as $ligne) {
            if (!\is_string($ligne) || !str_contains($ligne, '=')) {
                $io->error(\sprintf('« %s » n\'est pas au format REFERENCE=quantité.', (string) $ligne));

                return Command::INVALID;
            }
            [$reference, $quantite] = explode('=', $ligne, 2);
            $lignes[$reference] = (int) $quantite;
        }

        $io->comment(\sprintf('commande %s — %s', $commande, json_encode($lignes, \JSON_THROW_ON_ERROR)));

        // Les clés de la charge sont les noms des paramètres du workflow, pas leur position :
        // `mapInputToArguments` associe par nom, et un renommage d'un seul côté donnerait `null`.
        $this->client->startAsync(
            ReserverStockWorkflow::TYPE,
            ['commande' => $commande, 'lignes' => $lignes],
            $commande,
        );

        $secondes = max(1, (int) $input->getOption('timeout'));
        $verdict = $this->client->pollForCompletion($commande, 500, $secondes * 2);

        $io->writeln(json_encode($verdict, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        if (\is_array($verdict) && true === ($verdict['reserve'] ?? null)) {
            $io->success('La boutique a retenu le stock.');

            return Command::SUCCESS;
        }

        $io->warning('La boutique n\'a pas pu tout retenir.');

        return Command::SUCCESS;
    }
}
