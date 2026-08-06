<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Manager;
use App\Entity\OrgUser;
use App\Entity\Organization;
use App\Entity\WorkSession;
use App\Service\SessionSweeperService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CoreFeaturesWorkflowTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Manager $manager;
    private Organization $org;
    private OrgUser $user;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testForceCheckoutWorkflow(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $org = $manager->getOrganization();

        $client->loginUser($manager);

        // 1. Create a test student and an active work session
        $randPin = 'A' . rand(100, 999);
        $user = new OrgUser();
        $user->setFirstName('ForceTest');
        $user->setLastName('Student');
        $user->setPin($randPin);
        $user->setPinHash(hash('sha256', $randPin));
        $user->setOrganization($org);
        $em->persist($user);

        $session = new WorkSession();
        $session->setOrgUser($user);
        $session->setOrganization($org);
        $session->setStartedAt(new \DateTime('-45 minutes'));
        $session->setStartMethod('Kiosk');
        $em->persist($session);
        $em->flush();

        $sessionId = $session->getId();

        // 2. Perform Force Checkout / Force End action from Manager Console
        $client->request('POST', "/manager/reports/force-end/{$sessionId}");
        $this->assertResponseRedirects('/manager/reports');

        // 3. Verify session was cleanly ended with 'Force End' and duration calculated
        $em->clear();
        $updatedSession = $em->getRepository(WorkSession::class)->find($sessionId);
        $this->assertNotNull($updatedSession);
        $this->assertNotNull($updatedSession->getEndedAt());
        $this->assertEquals('Force End', $updatedSession->getEndMethod());
        $this->assertGreaterThan(0, $updatedSession->getDurationSeconds());
    }

    public function testAutoEndSessionSweeperWorkflow(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $org = $manager->getOrganization();
        $org->setSessionLimitHoursOverride(1);
        $em->persist($org);

        // 1. Create an active session that started 3 hours ago (exceeding org 1-hour session limit)
        $randPin = 'B' . rand(100, 999);
        $user = new OrgUser();
        $user->setFirstName('AutoEndTest');
        $user->setLastName('Student');
        $user->setPin($randPin);
        $user->setPinHash(hash('sha256', $randPin));
        $user->setOrganization($org);
        $em->persist($user);

        $session = new WorkSession();
        $session->setOrgUser($user);
        $session->setOrganization($org);
        $session->setStartedAt(new \DateTime('-3 hours'));
        $session->setStartMethod('Kiosk');
        $em->persist($session);
        $em->flush();

        $sessionId = $session->getId();

        // 2. Execute SessionSweeperService
        $sweeper = $container->get(SessionSweeperService::class);
        $sweptCount = $sweeper->sweepActiveSessions($org);

        $this->assertGreaterThanOrEqual(1, $sweptCount);

        // 3. Verify session was auto-ended with 'Auto End' status
        $em->clear();
        $updatedSession = $em->getRepository(WorkSession::class)->find($sessionId);
        $this->assertNotNull($updatedSession);
        $this->assertNotNull($updatedSession->getEndedAt());
        $this->assertEquals('Auto End', $updatedSession->getEndMethod());
    }

    public function testBarcodeStickerBadgesRendering(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $org = $manager->getOrganization();
        $client->loginUser($manager);

        // Get a student user from org
        $user = $em->getRepository(OrgUser::class)->findOneBy(['organization' => $org]);
        $this->assertNotNull($user);

        // 1. Test Single User Sticker Badge (template 5160)
        $client->request('GET', "/manager/users/print-barcodes?id={$user->getId()}&template=5160");
        $this->assertResponseIsSuccessful();

        // 2. Test All Roster Sticker Badges (template L7156)
        $client->request('GET', "/manager/users/print-barcodes?template=L7156");
        $this->assertResponseIsSuccessful();
    }

    public function testCategorySessionDurationsForStudentsAndInstructors(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $org = $manager->getOrganization();

        // 1. Create a Student Shift Category with 60-minute max duration
        $studentCategory = new Category();
        $studentCategory->setName('Student Tutoring ' . rand(100, 999));
        $studentCategory->setMaxSessionDurationMinutes(60);
        $studentCategory->setTerminateAtMaxDuration(true);
        $studentCategory->setOrganization($org);
        $em->persist($studentCategory);

        // 2. Create an Instructor Shift Category with 480-minute (8h) max duration
        $instructorCategory = new Category();
        $instructorCategory->setName('Instructor Shift ' . rand(100, 999));
        $instructorCategory->setMaxSessionDurationMinutes(480);
        $instructorCategory->setTerminateAtMaxDuration(false);
        $instructorCategory->setOrganization($org);
        $em->persist($instructorCategory);

        $em->flush();

        $this->assertEquals(60, $studentCategory->getMaxSessionDurationMinutes());
        $this->assertTrue($studentCategory->isTerminateAtMaxDuration());

        $this->assertEquals(480, $instructorCategory->getMaxSessionDurationMinutes());
        $this->assertFalse($instructorCategory->isTerminateAtMaxDuration());
    }
}
