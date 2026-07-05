<?php

namespace App\Command;

use App\Entity\Organization;
use App\Service\SessionSweeperService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sweep-sessions',
    description: 'Automatically ends work sessions that have exceeded their maximum duration based on their organization plan.',
)]
class SweepActiveSessionsCommand extends Command
{
    private EntityManagerInterface $em;
    private SessionSweeperService $sessionSweeper;

    public function __construct(EntityManagerInterface $em, SessionSweeperService $sessionSweeper)
    {
        parent::__construct();
        $this->em = $em;
        $this->sessionSweeper = $sessionSweeper;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $orgs = $this->em->getRepository(Organization::class)->findAll();
        
        $io->title('Sweeping Active Sessions');

        $count = 0;
        foreach ($orgs as $org) {
            $this->sessionSweeper->sweepActiveSessions($org);
            $count++;
        }

        $io->success(sprintf('Swept active sessions for %d organizations.', $count));

        return Command::SUCCESS;
    }
}
