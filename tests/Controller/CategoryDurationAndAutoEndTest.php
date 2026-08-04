<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\OrgUser;
use App\Entity\Organization;
use App\Entity\Plan;
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
        $goldPlan = $em->getRepository(Plan::class)->findOneBy(['name' => 'gold']);
        if ($goldPlan) {
            $org->setPlan($goldPlan);
            $em->flush();
        }

        // Find an orgUser
        $orgUser = $em->getRepository(OrgUser::class)->findOneBy(['organization' => $org]);
        $this->assertNotNull($orgUser);

        $org->setSessionLimitHoursOverride(4);
        $em->flush();

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

        // 3. Create an active work session for the terminating category, started 3.5 hours ago (exceeded category's 3h limit)
        $sessionTerminating = new WorkSession();
        $sessionTerminating->setOrgUser($orgUser);
        $sessionTerminating->setOrganization($org);
        $sessionTerminating->setCategory($category);
        $sessionTerminating->setStartedAt(new \DateTime('-210 minutes'));
        $sessionTerminating->setStartMethod('secure');

        $em->persist($sessionTerminating);

        // 4. Create an active work session for the non-terminating category, started 3.5 hours ago (exceeded category's 3h but not org's 4h limit)
        $sessionOvertime = new WorkSession();
        $sessionOvertime->setOrgUser($orgUser);
        $sessionOvertime->setOrganization($org);
        $sessionOvertime->setCategory($categoryNoTerminate);
        $sessionOvertime->setStartedAt(new \DateTime('-210 minutes'));
        $sessionOvertime->setStartMethod('secure');

        $em->persist($sessionOvertime);

        // 5. Create an active work session for the non-terminating category, started 5 hours ago (exceeded both category's 3h and org's 4h limit)
        $sessionOvertimeExceededOrg = new WorkSession();
        $sessionOvertimeExceededOrg->setOrgUser($orgUser);
        $sessionOvertimeExceededOrg->setOrganization($org);
        $sessionOvertimeExceededOrg->setCategory($categoryNoTerminate);
        $sessionOvertimeExceededOrg->setStartedAt(new \DateTime('-5 hours'));
        $sessionOvertimeExceededOrg->setStartMethod('secure');

        $em->persist($sessionOvertimeExceededOrg);

        $em->flush();

        // 6. Trigger Kiosk index page to run the sweepActiveSessions call
        $client->request('GET', '/org/acme-corp/kiosk/acme-secret-token');
        $this->assertResponseIsSuccessful();

        // Clear EntityManager to get fresh entity states from DB
        $em->clear();

        // 7. Assertions on Terminating session (ended at category max 3 hours)
        $dbSessionTerminating = $em->getRepository(WorkSession::class)->find($sessionTerminating->getId());
        $this->assertNotNull($dbSessionTerminating->getEndedAt());
        $this->assertEquals('Auto End', $dbSessionTerminating->getEndMethod());
        $this->assertEquals(3 * 3600, $dbSessionTerminating->getDurationSeconds());
        $this->assertFalse($dbSessionTerminating->isOvertime());

        // 8. Assertions on Non-terminating session (started 3.5h ago, org limit is 4h, so remains active)
        $dbSessionOvertime = $em->getRepository(WorkSession::class)->find($sessionOvertime->getId());
        $this->assertNull($dbSessionOvertime->getEndedAt()); // remains active

        // 9. Assertions on Non-terminating session that exceeds org limit (started 5h ago, org limit is 4h, so is terminated at 4h)
        $dbSessionOvertimeExceededOrg = $em->getRepository(WorkSession::class)->find($sessionOvertimeExceededOrg->getId());
        $this->assertNotNull($dbSessionOvertimeExceededOrg->getEndedAt());
        $this->assertEquals('Auto End', $dbSessionOvertimeExceededOrg->getEndMethod());
        $this->assertEquals(4 * 3600, $dbSessionOvertimeExceededOrg->getDurationSeconds());
        $this->assertFalse($dbSessionOvertimeExceededOrg->isOvertime());
    }
}
