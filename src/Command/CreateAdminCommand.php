<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Crée un utilisateur administrateur'
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔐 Création d\'un administrateur');

        // Demander l'email avec SymfonyStyle
        $email = $io->ask('Email de l\'utilisateur');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Email invalide!');
            return Command::FAILURE;
        }

        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy(['email' => $email]);

        if ($user) {
            if (in_array('ROLE_ADMIN', $user->getRoles())) {
                $io->warning('Cet utilisateur est déjà administrateur!');
                return Command::SUCCESS;
            }

            $roles = $user->getRoles();
            $roles[] = 'ROLE_ADMIN';
            $user->setRoles(array_unique($roles));
            $this->entityManager->flush();

            $io->success("✅ {$email} est maintenant administrateur!");
        } else {
            $io->warning("L'utilisateur {$email} n'existe pas encore.");
            $io->note([
                "Connectez-vous d'abord via le site avec Google,",
                "puis relancez cette commande."
            ]);
        }

        return Command::SUCCESS;
    }
}
