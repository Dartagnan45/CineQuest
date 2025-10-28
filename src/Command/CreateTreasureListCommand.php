<?php

namespace App\Command;

use App\Entity\MovieList;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:create-treasure-lists',
    description: 'Crée "La Carte aux Trésors" pour tous les utilisateurs',
)]
class CreateTreasureListCommand extends Command
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $users = $this->userRepository->findAll();
        $count = 0;

        foreach ($users as $user) {
            // Vérifie si l'utilisateur a déjà "La Carte aux Trésors"
            $hasCarteTresors = false;
            foreach ($user->getMovieLists() as $list) {
                if ($list->getName() === 'La Carte aux Trésors') {
                    $hasCarteTresors = true;
                    break;
                }
            }

            if (!$hasCarteTresors) {
                $treasureList = new MovieList();
                $treasureList->setName('La Carte aux Trésors');
                $treasureList->setUser($user);
                $treasureList->setCreatedAt(new \DateTimeImmutable());
                $treasureList->setIsSystem(true);

                $this->entityManager->persist($treasureList);
                $count++;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('✅ %d liste(s) "La Carte aux Trésors" créée(s)', $count));

        return Command::SUCCESS;
    }
}
