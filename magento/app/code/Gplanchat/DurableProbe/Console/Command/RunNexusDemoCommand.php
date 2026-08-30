<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Console\Command;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableProbe\Workflow\CommandeNexusWorkflow;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento durable:demo:nexus <commande> <montant> REF=qté …` — Magento appelle les deux autres.
 *
 * **Sur la grappe, et non ici.** `MagentoRuntime::run()` exécuterait le workflow dans ce processus,
 * ce qui n'est pas ce que la démonstration montre : une opération Nexus est servie par une autre
 * application, et l'exécution qui l'attend doit survivre à la commande qui l'a lancée.
 * `workflowClient()->startAsync()` la confie au cluster ; le worker de journal du banc la fait
 * avancer, et cette commande ne fait plus qu'attendre le résultat pour l'imprimer.
 *
 * Elle ne prouve donc rien toute seule : sans `bin/magento durable:worker --role=journal` en face,
 * l'exécution démarre et reste là. C'est vrai des deux autres maquettes aussi, et c'est
 * `demo/lancer.sh` qui compte les processus.
 */
/*
 * Pas `final` : Magento engendre un `Interceptor` qui étend toute classe que son conteneur
 * instancie, pour porter les plugins. Une classe finale fait échouer la compilation du conteneur.
 */
class RunNexusDemoCommand extends Command
{
    public function __construct(
        private readonly RuntimeFactory $runtimeFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('durable:demo:nexus')
            ->setDescription('Reserves stock in the Sylius shop and gets billed by the Symfony application, through Nexus')
            ->addArgument('commande', InputArgument::REQUIRED, 'Identifiant de commande — c\'est lui qui rend la réservation idempotente')
            ->addArgument('montant', InputArgument::REQUIRED, 'Montant à facturer, en centimes')
            ->addArgument('lignes', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'REFERENCE=quantité, une ou plusieurs')
            ->addOption('devise', null, InputOption::VALUE_REQUIRED, 'Code ISO 4217', 'EUR')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Secondes d\'attente', '120');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commande = (string) $input->getArgument('commande');

        $lignes = [];
        foreach ((array) $input->getArgument('lignes') as $ligne) {
            if (!\is_string($ligne) || !str_contains($ligne, '=')) {
                $output->writeln(sprintf('<error>« %s » n\'est pas au format REFERENCE=quantité.</error>', (string) $ligne));

                return Command::INVALID;
            }
            [$reference, $quantite] = explode('=', $ligne, 2);
            $lignes[$reference] = (int) $quantite;
        }

        // Le message d'un DSN manquant vient de la fabrique, et il nomme `app/etc/env.php` : le
        // rattraper ici pour le réécrire ne ferait que le dire moins bien.
        $client = $this->runtimeFactory->workflowClient();

        $output->writeln(sprintf('  commande %s — %s', $commande, json_encode($lignes, \JSON_THROW_ON_ERROR)));
        $depart = microtime(true);

        // Les clés de la charge sont les **noms** des paramètres du workflow, pas leur position :
        // `mapInputToArguments` associe par nom, et un renommage d'un seul côté donnerait `null`.
        $client->startAsync(
            CommandeNexusWorkflow::class,
            [
                'commande' => $commande,
                'lignes' => $lignes,
                'montant' => (int) $input->getArgument('montant'),
                'devise' => (string) $input->getOption('devise'),
            ],
            $commande,
        );

        $output->writeln('  démarré — Magento ne tient rien d\'ouvert pendant que les deux autres travaillent.');

        $secondes = max(1, (int) $input->getOption('timeout'));
        $resultat = $client->pollForCompletion($commande, 500, $secondes * 2);

        $output->writeln(json_encode($resultat, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        // Le commentaire de durée dit ce qui s'est passé, et non ce qui se passe d'habitude : une
        // commande refusée revient en un dixième de seconde, et annoncer « dont l'encaissement »
        // ferait passer un refus rapide pour un encaissement anormalement véloce.
        $encaisse = \is_array($resultat) && null !== ($resultat['encaissement'] ?? null);
        $output->writeln(sprintf(
            '<info>%.1f s%s</info>',
            microtime(true) - $depart,
            $encaisse ? ' — dont l\'encaissement, rempli par un workflow d\'en face.' : ' — rien n\'a été encaissé.',
        ));

        return $encaisse ? Command::SUCCESS : Command::FAILURE;
    }
}
