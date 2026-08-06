<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\OrgUser;
use App\Entity\Plan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ComprehensiveReportsAndAuditLogsTest extends WebTestCase
{
    public function testManagerReportsCsvExport(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // Find Acme Corp manager (Gold plan has export enabled)
        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        
        $goldPlan = $em->getRepository(Plan::class)->findOneBy(['name' => 'gold']);
        if ($goldPlan) {
            $manager->getOrganization()->setPlan($goldPlan);
            $em->flush();
        }

        $client->loginUser($manager);

        // Request CSV Export with filters
        $client->request('GET', '/manager/reports/export', [
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31'
        ]);

        $response = $client->getResponse();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        
        // Assert CSV Headers
        $content = $response->getContent();
        $this->assertStringContainsString('User', $content);
        $this->assertStringContainsString('Category', $content);
        $this->assertStringContainsString('Started At', $content);
        $this->assertStringContainsString('Ended At', $content);
    }

    public function testReportsByUserMonthNavigationCategoryAndViews(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);
        $client->loginUser($manager);

        $orgUser = $em->getRepository(OrgUser::class)->findOneBy(['organization' => $manager->getOrganization()]);
        $this->assertNotNull($orgUser);

        // 1. Calendar View with month parameter
        $client->request('GET', '/manager/reports/by-user', [
            'org_user_id' => $orgUser->getId(),
            'month' => '2026-05',
            'view' => 'calendar'
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#calendar-view-container');
        $this->assertSelectorTextContains('body', 'May 2026');

        // 2. Month navigation parameter change
        $client->request('GET', '/manager/reports/by-user', [
            'org_user_id' => $orgUser->getId(),
            'month' => '2026-06',
            'view' => 'calendar'
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'June 2026');

        // 3. List View toggle
        $client->request('GET', '/manager/reports/by-user', [
            'org_user_id' => $orgUser->getId(),
            'month' => '2026-06',
            'view' => 'list'
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#list-view-container');

        // 4. Assert highlight controls exist
        $this->assertSelectorExists('.highlight-filter-btn[data-seconds="1800"]');
        $this->assertSelectorExists('#highlight-custom-btn');
    }

    public function testManagerAuditLogsFiltering(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);

        // 1. Request default manager audit logs
        $client->request('GET', '/manager/audit-logs');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h3', 'Organization Audit Logs');

        // 2. Request manager audit logs with action filter
        $client->request('GET', '/manager/audit-logs', [
            'action' => 'LOGIN',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31'
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('table');
    }

    public function testAdminAuditLogsFiltering(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Manager::class)->findOneBy(['email' => 'admin@centertime.us']);
        $client->loginUser($admin);

        // 1. Request default admin audit logs
        $client->request('GET', '/admin/audit-logs');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h3', 'System-wide Audit Trail');

        // 2. Request admin audit logs with organization and action parameters
        $acmeOrg = $em->getRepository(Organization::class)->findOneBy(['slug' => 'acme-corp']);
        $client->request('GET', '/admin/audit-logs', [
            'org' => $acmeOrg ? $acmeOrg->getId() : null,
            'action' => 'LOGIN'
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('table');
    }
}
