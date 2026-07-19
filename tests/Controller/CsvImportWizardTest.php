<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class CsvImportWizardTest extends WebTestCase
{
    public function testCsvUploadAndStepMapping(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $client->loginUser($manager);

        // Create a temporary CSV file
        $csvPath = tempnam(sys_get_temp_dir(), 'import_test');
        file_put_contents($csvPath, "first_name,last_name,phone_number,email_addr,pin_code\nJohn,Doe,555-010-1111,j.doe@example.com,1234");

        $uploadedFile = new UploadedFile(
            $csvPath,
            'test_roster.csv',
            'text/csv',
            null,
            true // test mode
        );

        // 1. Submit step 1 (upload file)
        $client->request('POST', '/manager/users/import', [
            'step' => 1
        ], [
            'import_file' => $uploadedFile
        ]);

        $this->assertResponseIsSuccessful();

        // Check if page contains Step 2 (column mapping selector)
        $this->assertSelectorExists('select[name="mappings[first_name]"]');
        $this->assertSelectorExists('select[name="mappings[phone]"]');
    }
}
