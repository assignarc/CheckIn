<?php

namespace App\Tests\Controller;

use App\Entity\OrgUser;
use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BusinessEventsAndBarcodeFilterTest extends WebTestCase
{
    public function testBarcodeFilterAndActiveRestriction(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // Find Acme Corp manager
        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);

        // Log in manager
        $client->loginUser($manager);

        // Generate a random PIN to avoid duplicate entry constraint violations
        $pin1 = 'A' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5);
        $pin2 = 'B' . substr(md5(uniqid((string)mt_rand(), true)), 0, 5);

        // 1. Create a user created 5 days ago (active)
        $user1 = new OrgUser();
        $user1->setOrganization($manager->getOrganization());
        $user1->setFirstName('ActiveOld');
        $user1->setLastName('User');
        $user1->setIsActive(true);
        $user1->setPin($pin1);
        $user1->setPinHash(password_hash($pin1, PASSWORD_BCRYPT));
        
        // Use reflection to set createdAt manually for testing the date filters
        $ref = new \ReflectionClass(OrgUser::class);
        $prop = $ref->getProperty('createdAt');
        $prop->setValue($user1, new \DateTimeImmutable('-5 days'));

        $em->persist($user1);

        // 2. Create another user created today (inactive)
        $user2 = new OrgUser();
        $user2->setOrganization($manager->getOrganization());
        $user2->setFirstName('InactiveNew');
        $user2->setLastName('User');
        $user2->setIsActive(false);
        $user2->setPin($pin2);
        $user2->setPinHash(password_hash($pin2, PASSWORD_BCRYPT));
        $em->persist($user2);

        $em->flush();

        // Query print barcodes page with date filters
        $fiveDaysAgo = (new \DateTime('-6 days'))->format('Y-m-d');
        $fourDaysAgo = (new \DateTime('-4 days'))->format('Y-m-d');

        // Request with date range that only covers the old user
        $client->request('GET', '/manager/users/print-barcodes', [
            'start_date' => $fiveDaysAgo,
            'end_date' => $fourDaysAgo
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'ActiveOld');
        $this->assertSelectorTextNotContains('body', 'InactiveNew');

        // Request without date range (should return only active users)
        $client->request('GET', '/manager/users/print-barcodes');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'ActiveOld');
        $this->assertSelectorTextNotContains('body', 'InactiveNew'); // Inactive users should not be listed
    }

    public function testBusinessEventsAuditLogs(): void
    {
        $client = static::createClient();
        
        // Find Acme Corp manager
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);

        // 1. Trigger User Updated Event (Toggle status)
        $orgUser = $em->getRepository(OrgUser::class)->findOneBy(['organization' => $manager->getOrganization(), 'isActive' => true]);
        $this->assertNotNull($orgUser);

        $client->request('POST', '/manager/users/toggle/' . $orgUser->getId());
        $this->assertResponseRedirects('/manager/users');

        // Verify Audit Log action
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'organization' => $manager->getOrganization(),
            'action' => 'USER_TOGGLE'
        ], ['id' => 'DESC']);
        $this->assertNotNull($log);

        // 2. Trigger Org Profile Update Event
        $client->request('POST', '/manager/settings', [
            '_section' => 'org_profile',
            'org_name' => 'Acme Corp Renovated'
        ]);
        $this->assertResponseRedirects('/manager/settings');

        // Verify Audit Log action
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'organization' => $manager->getOrganization(),
            'action' => 'ORG_PROFILE_UPDATE'
        ], ['id' => 'DESC']);
        $this->assertNotNull($log);

        // 3. Trigger Password Changed Event (Self password)
        $client->request('POST', '/manager/settings', [
            '_section' => 'my_account',
            'first_name' => $manager->getFirstName(),
            'last_name' => $manager->getLastName(),
            'email' => $manager->getEmail(),
            'password' => 'newsecurepassword123'
        ]);
        $this->assertResponseRedirects('/manager/settings');

        // Verify Audit Log action
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'organization' => $manager->getOrganization(),
            'action' => 'PASSWORD_CHANGE_SELF'
        ], ['id' => 'DESC']);
        $this->assertNotNull($log);

        // Restore org name to prevent test database contamination
        $testEm = static::getContainer()->get(EntityManagerInterface::class);
        $testOrg = $testEm->getRepository(Organization::class)->find(1);
        if ($testOrg) {
            $testOrg->setName('Acme Corporation');
            $testEm->flush();
        }
    }
}
