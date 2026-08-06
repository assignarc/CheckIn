<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\OrgUser;
use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class CsvImportFullWorkflowTest extends WebTestCase
{
    public function testFullCsvImportWorkflow(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $org = $manager->getOrganization();

        $client->loginUser($manager);

        // Unique PINs and Names per test run
        $randId = rand(10000, 99999);
        $pinExisting = 'A' . rand(100, 999);
        $pinOmitted = 'B' . rand(100, 999);
        $pinNew = 'C' . rand(100, 999);

        $firstExisting = 'Exist' . $randId;
        $firstOmitted  = 'Omit'  . $randId;
        $firstNew      = 'New'   . $randId;

        $userMatchingService = $container->get(\App\Service\UserMatchingService::class);

        // Pre-create an existing user to test matching by unique identifier
        $existingUser = new OrgUser();
        $existingUser->setFirstName($firstExisting);
        $existingUser->setLastName('Student');
        $existingUser->setEmail("existing_{$randId}@acme.com");
        $existingUser->setPhone('+15550109999');
        $existingUser->setPin($pinExisting);
        $existingUser->setPinHash(hash('sha256', $pinExisting));
        $existingUser->setUniqueIdHash($userMatchingService->generateHash($pinExisting));
        $existingUser->setOrganization($org);
        $em->persist($existingUser);

        // Pre-create an existing user that will NOT be in the CSV (missing in CSV case)
        $omittedUser = new OrgUser();
        $omittedUser->setFirstName($firstOmitted);
        $omittedUser->setLastName('Student');
        $omittedUser->setEmail("omitted_{$randId}@acme.com");
        $omittedUser->setPhone('+15550107777');
        $omittedUser->setPin($pinOmitted);
        $omittedUser->setPinHash(hash('sha256', $pinOmitted));
        $omittedUser->setOrganization($org);
        $em->persist($omittedUser);

        $em->flush();

        // Create CSV file containing:
        // 1. Existing user (matched by PIN $pinExisting and email existing_{$randId}@acme.com)
        // 2. Brand-new user ($firstNew Student, new_{$randId}@acme.com, PIN $pinNew)
        $csvContent = "first_name,last_name,email,phone,pin\n" .
                     "{$firstExisting},Student Updated,existing_{$randId}@acme.com,555-010-9999,{$pinExisting}\n" .
                     "{$firstNew},StudentNew,new_{$randId}@acme.com,555-010-8888,{$pinNew}\n";

        $csvPath = tempnam(sys_get_temp_dir(), 'csv_import_full_test');
        file_put_contents($csvPath, $csvContent);

        $uploadedFile = new UploadedFile($csvPath, 'import_roster.csv', 'text/csv', null, true);

        // Step 1: Upload CSV file
        $client->request('POST', '/manager/users/import', [
            'step' => 1
        ], [
            'import_file' => $uploadedFile
        ]);
        $this->assertResponseIsSuccessful();

        // Step 2: Submit column mappings with PIN as unique matching column
        $client->request('POST', '/manager/users/import', [
            'step' => 2,
            'unique_col' => '4',
            'mappings' => [
                'first_name' => '0',
                'last_name' => '1',
                'email' => '2',
                'phone' => '3',
                'pin' => '4',
            ]
        ]);
        $this->assertResponseIsSuccessful();

        // Step 3: Submit action selections for each analyzed row
        $client->request('POST', '/manager/users/import', [
            'step' => 3,
            'actions' => [
                0 => 'update', // Update existing user matched by email/pin
                1 => 'add',    // Create brand new user
            ],
            'enable_inactive' => '0',
        ]);
        $this->assertResponseIsSuccessful();

        // Step 4: Specify missing users action and display quota preview
        $client->request('POST', '/manager/users/import', [
            'step' => 4,
            'missing_action' => 'keep', // Keep omitted user untouched
        ]);
        $this->assertResponseIsSuccessful();

        // Step 5: Finalize import execution in transaction
        $client->request('POST', '/manager/users/import', [
            'step' => 5,
            'wizard_action' => 'confirm',
        ]);
        $this->assertResponseRedirects('/manager/users');

        // Refresh database state
        $em->clear();

        // Assertion 1: Check existing user was updated/matched correctly on unique identifier (PIN/Email)
        $matchedUser = $em->getRepository(OrgUser::class)->findOneBy(['pin' => $pinExisting, 'organization' => $org]);
        $this->assertNotNull($matchedUser);
        $this->assertEquals('Student Updated', $matchedUser->getLastName());

        // Assertion 2: Check brand-new user was created
        $newUser = $em->getRepository(OrgUser::class)->findOneBy(['pin' => $pinNew, 'organization' => $org]);
        $this->assertNotNull($newUser);
        $this->assertEquals($firstNew, $newUser->getFirstName());
        $this->assertEquals("new_{$randId}@acme.com", $newUser->getEmail());

        // Assertion 3: Check omitted user still exists unharmed in organization
        $stillOmitted = $em->getRepository(OrgUser::class)->findOneBy(['pin' => $pinOmitted, 'organization' => $org]);
        $this->assertNotNull($stillOmitted);
        $this->assertEquals($firstOmitted, $stillOmitted->getFirstName());
    }
}
