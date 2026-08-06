<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\OrgUser;
use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\WorkSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RecentlyFixedIssuesTest extends WebTestCase
{
    /**
     * Test Checked-in users page rendering and data structure
     */
    public function testCheckedInUsersPageLoadsAndRendersTimers(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // Find Acme Corp manager and log in
        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $client->loginUser($manager);

        // Ensure there is at least one active work session
        $orgUser = $em->getRepository(OrgUser::class)->findOneBy(['organization' => $manager->getOrganization()]);
        $this->assertNotNull($orgUser);

        $session = new WorkSession();
        $session->setOrgUser($orgUser);
        $session->setOrganization($manager->getOrganization());
        $session->setStartedAt(new \DateTime('-45 minutes'));
        $session->setStartMethod('secure');
        $em->persist($session);
        $em->flush();

        $client->request('GET', '/manager/users/checked-in');
        $this->assertResponseIsSuccessful();

        // Verify the standard manager layout is used
        $this->assertSelectorExists('.sidebar');
        $this->assertSelectorExists('.dashboard-header');

        // Verify that the table renders the active session row
        $this->assertSelectorTextContains('table', $orgUser->getFirstName());
        $this->assertSelectorTextContains('table', $orgUser->getLastName());

        // Verify that Time Elapsed does NOT render as "Calculating..." but as a real pre-computed value
        $this->assertSelectorTextNotContains('table', 'Calculating...');
        // Should contain something like "45m"
        $this->assertSelectorTextContains('table', '45m');

        // Cleanup
        $em->remove($session);
        $em->flush();
    }

    /**
     * Verify that /manager/users/print-barcodes extends the standard manager layout
     */
    public function testPrintBarcodesExtendsManagerLayout(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);

        $client->request('GET', '/manager/users/print-barcodes');
        $this->assertResponseIsSuccessful();

        // Assert sidebar and header elements are present, confirming layout extension
        $this->assertSelectorExists('.sidebar');
        $this->assertSelectorExists('.dashboard-header');

        // Assert printable barcode container and barcode/QR code images are rendered
        $this->assertSelectorExists('.avery-print-container');
        $this->assertSelectorExists('.label-code img');
    }

    /**
     * Test Settings Semi-Permanent Kiosk Token rotation and QR data API endpoint
     */
    public function testKioskSettingsEndpoints(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $org = $manager->getOrganization();
        $client->loginUser($manager);

        // 1. Test Rotate Token POST request
        $oldToken = $org->getSemiPermanentKioskToken();
        
        $client->request('POST', '/manager/settings/rotate-semi-permanent-token');
        $this->assertResponseRedirects('/manager/settings?_tab=semi-perm');

        $em->clear();
        $updatedOrg = $em->getRepository(Organization::class)->find($org->getId());
        $this->assertNotEquals($oldToken, $updatedOrg->getSemiPermanentKioskToken());

        // 2. Test QR Data API GET request
        $client->request('GET', '/manager/settings/kiosk-qr-data');
        $this->assertResponseIsSuccessful();
        
        $response = $client->getResponse();
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        
        $json = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('qrDataUrl', $json);
        $this->assertArrayHasKey('kioskUrl', $json);
        $this->assertArrayHasKey('orgName', $json);
        $this->assertArrayHasKey('orgSlug', $json);
        $this->assertStringStartsWith('data:image/png;base64,', $json['qrDataUrl']);
    }

    /**
     * Test that a session with terminateAtMaxDuration = false correctly falls back to organization limit
     */
    public function testSessionSweeperFallbackToOrgLimit(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // Acme Corp has a Gold plan, limit = 4 hours.
        $org = $em->getRepository(Organization::class)->findOneBy(['slug' => 'acme-corp']);
        $orgUser = $em->getRepository(OrgUser::class)->findOneBy(['organization' => $org]);

        // Create a non-terminating category with max duration = 3h (180 mins)
        $category = new Category();
        $category->setOrganization($org);
        $category->setName('Non Terminating Custom Category');
        $category->setIcon('schedule');
        $category->setIsActive(true);
        $category->setMaxSessionDurationMinutes(180);
        $category->setTerminateAtMaxDuration(false);
        $em->persist($category);

        // Create active session started 5 hours ago (exceeds org limit 4h)
        $session = new WorkSession();
        $session->setOrgUser($orgUser);
        $goldPlan = $em->getRepository(\App\Entity\Plan::class)->findOneBy(['name' => 'gold']);
        if ($goldPlan) {
            $org->setPlan($goldPlan);
        }
        $org->setSessionLimitHoursOverride(4);

        $session->setOrganization($org);
        $session->setCategory($category);
        $session->setStartedAt(new \DateTime('-300 minutes')); // 5 hours ago
        $session->setStartMethod('secure');
        $em->persist($session);
        
        $em->flush();

        // Trigger sweeper by requesting kiosk page
        $client->request('GET', '/org/acme-corp/kiosk/acme-secret-token');
        $this->assertResponseIsSuccessful();

        $em->clear();
        $dbSession = $em->getRepository(WorkSession::class)->find($session->getId());
        
        // Assert that the session has been terminated after falling back to org limit (4 hours)
        $this->assertNotNull($dbSession->getEndedAt());
        $this->assertEquals('Auto End', $dbSession->getEndMethod());
        $this->assertEquals(4 * 3600, $dbSession->getDurationSeconds());

        // Cleanup
        $em->remove($dbSession);
        $em->remove($em->getRepository(Category::class)->find($category->getId()));
        $em->flush();
    }
}
