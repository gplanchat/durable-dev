<?php

declare(strict_types=1);

namespace App\Command;

use App\Durable\Workflow\CommandeWorkflow;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Démarre l'appelant de l'autre sens : la boutique fait facturer une commande.
 *
 * Elle n'existe que dans le profil `demo` de la boutique — celui dont le journal est le cluster.
 * Le profil `dev`, qui sert `stock` depuis un journal DBAL, ne peut pas appeler d'opération Nexus :
 * un journal SQL n'a pas de serveur à qui adresser l'ordonnancement, et le refuse en le disant.
 */
#[AsCommand(
    name: 'durable:demo:facturer',
    description: 'Fait vérifier puis encaisser une commande par le métier, à travers Nexus',
)]
final class DemoNexusFacturationCommand extends Command
{
    public function __construct(
        // Optionnel : sans DSN Temporal, ce service n'existe pas, et la commande doit pouvoir se
        // charger quand même — sinon le conteneur du banc de test refuse de compiler.
        private readonly ?WorkflowClientInterface $client = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('commande', InputArgument::REQUIRED, 'Identifiant de commande')
            ->addArgument('montant', InputArgument::REQUIRED, 'Montant en centimes')
            ->addArgument('devise', InputArgument::OPTIONAL, 'Code ISO 4217', 'EUR')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Secondes d\'attente', '120')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $this->client) {
            $io->error([
                'Aucun client de workflow : ce profil n\'a pas de DSN Temporal.',
                'Un appel Nexus part d\'un workflow, et un workflow a besoin du cluster pour l\'ordonnancer.',
            ]);

            return Command::FAILURE;
        }

        $commande = (string) $input->getArgument('commande');
        $depart = microtime(true);

        $this->client->startAsync(
            CommandeWorkflow::TYPE,
            [
                'commande' => $commande,
                'montant' => (int) $input->getArgument('montant'),
                'devise' => (string) $input->getArgument('devise'),
            ],
            $commande,
        );

        $io->comment(\sprintf('%s démarré — la boutique ne tient rien d\'ouvert pendant l\'attente.', $commande));

        $secondes = max(1, (int) $input->getOption('timeout'));
        $resultat = $this->client->pollForCompletion($commande, 500, $secondes * 2);

        $io->writeln(json_encode($resultat, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
        $io->success(\sprintf('%.1f s — dont l\'encaissement, rempli par un workflow d\'en face.', microtime(true) - $depart));

        return Command::SUCCESS;
    }
}
