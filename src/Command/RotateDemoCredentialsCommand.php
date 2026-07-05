<?php

namespace App\Command;

use App\Entity\Organization;
use App\Message\RotateKioskTokenMessage;
use App\Message\RotatePasswordMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:rotate-demo-credentials',
    description: 'Dispatches messages to rotate credentials for demo organizations.',
)]
class RotateDemoCredentialsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $orgs = $this->entityManager->getRepository(Organization::class)->findBy([
            'slug' => ['basic-demo', 'golden-demo']
        ]);

        if (empty($orgs)) {
            $io->warning('No demo organizations found. Skip rotation.');
            return Command::SUCCESS;
        }

        foreach ($orgs as $org) {
            // Dispatch Kiosk Token Rotation
            $this->messageBus->dispatch(new RotateKioskTokenMessage($org->getId(), $org->getName()));
            
            // Dispatch Manager Password Rotation
            $managers = $this->entityManager->getRepository(\App\Entity\Manager::class)->findBy(['organization' => $org]);
            foreach ($managers as $manager) {
                // In demo, we just rotate all managers in the demo org
                $this->messageBus->dispatch(new RotatePasswordMessage($org->getId(), $manager->getId()));
            }
        }

        $io->success('Dispatched rotation messages for demo organizations.');

        return Command::SUCCESS;
    }
}
