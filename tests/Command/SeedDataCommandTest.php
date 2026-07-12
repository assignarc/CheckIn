<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the SeedDataCommand console execution.
 */
class SeedDataCommandTest extends KernelTestCase
{
    public function testExecuteSeedCommand(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        // Find the seeding command
        $command = $application->find('app:seed-data');
        $commandTester = new CommandTester($command);
        
        // Execute command
        $commandTester->execute([]);

        // Assert output
        $commandTester->assertCommandIsSuccessful();
        $output = $commandTester->getDisplay();
        
        $this->assertStringContainsString('Starting Database Seeding...', $output);
        $this->assertStringContainsString('Seeding completed successfully!', $output);
    }
}
