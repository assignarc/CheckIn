<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\OrgUser;
use App\Entity\Organization;
use App\Entity\WorkSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CategoryDurationAndAutoEndTest extends WebTestCase
{
    public function testCategoryCustomSettingsAndAutoTermination(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // Find Acme Corp (Gold plan)
        $org = $em->getRepository(Organization::class)->findOneBy(['slug' => 'acme-corp']);
        $this->assertNotNull($org);

        // Find an orgUser
        $orgUser = $em->getRepository(OrgUser::class)->findOneBy(['organization' => $org]);
        $this->assertNotNull($orgUser);

        // 1. Create a category with 3 hours (180 mins) max duration & terminate = true
        $category = new Category();
        $category->setOrganization($org);
        $category->setName('Test Terminating Category');
        $category->setIcon('code');
        $category->setIsActive(true);
        $category->setMaxSessionDurationMinutes(180);
        $category->setTerminateAtMaxDuration(true);

        $em->persist($category);

        // 2. Create another category with 3 hours (180 mins) max duration & terminate = false (will go to Red Overtime)
        $categoryNoTerminate = new Category();
        $categoryNoTerminate->setOrganization($org);
        $categoryNoTerminate->setName('Test Overtime Category');
        $categoryNoTerminate->setIcon('palette');
        $categoryNoTerminate->setIsActive(true);
        $categoryNoTerminate->setMaxSessionDurationMinutes(180);
        $categoryNoTerminate->setTerminateAtMaxDuration(false);

        $em->persist($categoryNoTerminate);

        // 3. Create an active work session for the terminating category, started 4 hours ago (exceeded)
        $sessionTerminating = new WorkSession();
        $sessionTerminating->setOrgUser($orgUser);
        $sessionTerminating->setOrganization($org);
        $sessionTerminating->setCategory($category);
        $sessionTerminating->setStartedAt(new \DateTime('-4 hours'));
        $sessionTerminating->setStartMethod('secure');

        $em->persist($sessionTerminating);

        // 4. Create an active work session for the non-terminating category, started 4 hours ago (exceeded but not terminating)
        $sessionOvertime = new WorkSession();
        $sessionOvertime->setOrgUser($orgUser);
        $sessionOvertime->setOrganization($org);
        $sessionOvertime->setCategory($categoryNoTerminate);
        $sessionOvertime->setStartedAt(new \DateTime('-4 hours'));
        $sessionOvertime->setStartMethod('secure');

        $em->persist($sessionOvertime);

        $em->flush();

        // 5. Trigger Kiosk index page to run the sweepActiveSessions call
        $client->request('GET', '/org/acme-corp/kiosk/acme-secret-token');
        $this->assertResponseIsSuccessful();

        // Clear EntityManager to get fresh entity states from DB
        $em->clear();

        // 6. Assertions on Terminating session
        $dbSessionTerminating = $em->getRepository(WorkSession::class)->find($sessionTerminating->getId());
        $this->assertNotNull($dbSessionTerminating->getEndedAt());
        $this->assertEquals('Auto End', $dbSessionTerminating->getEndMethod());
        $this->assertEquals(3 * 3600, $dbSessionTerminating->getDurationSeconds());
        $this->assertFalse($dbSessionTerminating->isOvertime());

        // 7. Assertions on Non-terminating session
        $dbSessionOvertime = $em->getRepository(WorkSession::class)->find($sessionOvertime->getId());
        $this->assertNull($dbSessionOvertime->getEndedAt()); // remains active
    }
}
