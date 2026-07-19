<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\OrgUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminAuditLogAndReportsTest extends WebTestCase
{
    public function testReportsAndAuditLogDashboards(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $admin = $em->getRepository(Manager::class)->findOneBy(['email' => 'admin@centertime.us']);
        $orgUser = $em->getRepository(OrgUser::class)->findOneBy(['organization' => $manager->getOrganization()]);

        // 1. Log in as manager and load reports by user page
        $client->loginUser($manager);
        
        // Load default page
        $client->request('GET', '/manager/reports/by-user');
        $this->assertResponseIsSuccessful();

        // Query with selected user, month filter, and list view
        $client->request('GET', '/manager/reports/by-user', [
            'org_user_id' => $orgUser->getId(),
            'month' => date('Y-m'),
            'view' => 'list'
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $orgUser->getFirstName());

        // Toggle back to calendar view
        $client->request('GET', '/manager/reports/by-user', [
            'org_user_id' => $orgUser->getId(),
            'month' => date('Y-m'),
            'view' => 'calendar'
        ]);
        $this->assertResponseIsSuccessful();

        // 2. Load manager audit log viewer
        $client->request('GET', '/manager/audit-logs');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h3', 'Organization Audit Logs');

        // 3. Log in as platform administrator and load admin audit logs
        $client->loginUser($admin);
        $client->request('GET', '/admin/audit-logs');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h3', 'System-wide Audit Trail');

        // Apply filters (filter by organization)
        $client->request('GET', '/admin/audit-logs', [
            'org' => $manager->getOrganization()->getId(),
            'action' => 'LOGIN'
        ]);
        $this->assertResponseIsSuccessful();
    }
}
