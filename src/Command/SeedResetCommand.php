<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:seed-reset',
    description: 'Clears and recreates all seed data.',
)]
class SeedResetCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $command = $this->getApplication()->find('app:seed-data');
        $arrayInput = new ArrayInput([
            '--clean' => true,
        ]);
        
        return $command->run($arrayInput, $output);
    }
}
