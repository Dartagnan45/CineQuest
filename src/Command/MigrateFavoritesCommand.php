<?php

namespace App\Command;

use App\Repository\MovieListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrate-favorites',
    description: 'Migre les listes "Favoris" vers "Mon Panthéon"',
)]
class MigrateFavoritesCommand extends Command
{
    public function __construct(
        private MovieListRepository $movieListRepository,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $favoritesLists = $this->movieListRepository->findBy(['name' => 'Favoris']);
        $count = 0;

        foreach ($favoritesLists as $list) {
            $list->setName('Mon Panthéon');
            $this->entityManager->persist($list);
            $count++;
        }

        $this->entityManager->flush();

        $io->success(sprintf('✅ %d liste(s) "Favoris" migrée(s) vers "Mon Panthéon"', $count));

        return Command::SUCCESS;
    }
}
