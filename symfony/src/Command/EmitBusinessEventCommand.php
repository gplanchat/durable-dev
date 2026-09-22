<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\Watch\BusinessEvent;
use App\Ai\Watch\WatchRouter;
use App\Ai\Watch\WatchSubject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le levier de la démonstration : publier un fait de l'application, à la main.
 *
 * Une commande plutôt qu'une route, et ce n'est pas de la commodité : c'est la forme qu'une vraie
 * intégration prend — un gestionnaire Messenger sur un événement métier —, et ça montre ce qu'il
 * faut montrer, un fait levé **hors de toute requête web** qui réveille un workflow endormi depuis
 * des heures.
 */
#[AsCommand(
    name: 'app:agent:evenement',
    description: 'Publie un événement métier et réveille les agents qui le guettaient.',
)]
final class EmitBusinessEventCommand extends Command
{
    public function __construct(
        private readonly WatchRouter $router,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sujet', InputArgument::REQUIRED, 'Un de : ' . implode(', ', WatchSubject::values()))
            ->addArgument('details', InputArgument::IS_ARRAY, 'Précisions `clé=valeur`, rendues à l\'agent au réveil');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $subject = WatchSubject::tryFrom((string) $input->getArgument('sujet'));
        if (null === $subject) {
            $io->error('Sujet inconnu. Connus : ' . implode(', ', WatchSubject::values()));

            return Command::INVALID;
        }

        $details = [];
        foreach ((array) $input->getArgument('details') as $pair) {
            [$key, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $details[$key] = $value;
        }

        $reveilles = $this->router->dispatch(new BusinessEvent($subject, $details));

        if ([] === $reveilles) {
            $io->note('Personne ne guettait ' . $subject->value . '.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d veille(s) réveillée(s) : %s', \count($reveilles), implode(', ', $reveilles)));

        return Command::SUCCESS;
    }
}
