<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Console\Command;

use Gplanchat\Durable\Magento\Runtime\InMemoryRuntimeFactory;
use Gplanchat\Durable\Magento\Workflow\PlaceOrderWorkflow;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento durable:demo <orderId>` — la preuve que le module tourne.
 *
 * Un bootstrap de palier 1 n'a pas de test unitaire qui prouve qu'il démarre :
 * un module Magento ne se teste contre rien de plus petit que Magento. Cette
 * commande est donc le harnais, et elle vaut assertion — elle sort en erreur si
 * le workflow ne rend pas ce qu'il doit, et le journal qu'elle imprime dit
 * lequel des trois pas a eu lieu.
 *
 * Ce qu'elle ne prouve pas encore : rien de tout cela ne passe par la file de
 * Magento. Le backend est en mémoire, dans ce seul processus — donc rien ne
 * survit à la commande. C'est la tranche 4 qui met la file dessous, et la
 * tranche 5 qui met Temporal.
 */
/*
 * Pas `final` : Magento engendre un `Interceptor` qui étend toute classe que son
 * conteneur instancie, pour porter les plugins. Une classe finale fait échouer la
 * compilation du conteneur — « cannot extend final class » — et le message ne dit
 * pas que c'est la faute du mot-clé. C'est la maison qui écrit `final` partout ;
 * ici l'hôte l'interdit, et le dire vaut mieux que de le laisser deviner.
 */
class RunDemoCommand extends Command
{
    public function __construct(
        private readonly InMemoryRuntimeFactory $runtimeFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('durable:demo')
            ->setDescription('Runs a three-step order workflow inside Magento, on the in-memory backend')
            ->addArgument('order-id', InputArgument::OPTIONAL, 'The order identifier the workflow carries', 'ORD-4242');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $orderId = (string) $input->getArgument('order-id');
        $runtime = $this->runtimeFactory->create();

        // Les activités se déclarent : le conteneur de Magento n'a pas les tags
        // de Symfony, donc rien ne les ramasse tout seul. C'est le coût du palier 1.
        $seen = [];
        foreach (['charge', 'reserve', 'notify'] as $step) {
            $runtime->registerActivity(
                'durable.demo.'.$step,
                static function (array $payload) use ($step, &$seen): string {
                    $seen[] = $step;

                    return $step.':'.reset($payload);
                },
            );
        }

        $result = $runtime->run(PlaceOrderWorkflow::class, ['orderId' => $orderId]);

        foreach ($seen as $index => $step) {
            $output->writeln(sprintf('  %d. %s', $index + 1, $step));
        }
        $output->writeln(sprintf('  → %s', var_export($result, true)));

        if (['charge', 'reserve', 'notify'] !== $seen) {
            $output->writeln('<error>The three steps did not run in order.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>A workflow ran inside Magento, unmodified.</info>');

        return Command::SUCCESS;
    }
}
