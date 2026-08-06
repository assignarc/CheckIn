<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\OrgUser;
use App\Entity\Plan;
use App\Entity\WorkSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminPortalComprehensiveTest extends WebTestCase
{
    private function getAdminClient()
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Manager::class)->findOneBy(['email' => 'admin@centertime.us']);
        $this->assertNotNull($admin, 'Admin user admin@centertime.us must exist in test fixtures');
        $client->loginUser($admin);

        return [$client, $em, $admin];
    }

    public function testAdminActiveSessionsPageAndForceEnd(): void
    {
        [$client, $em] = $this->getAdminClient();

        $org = $em->getRepository(Organization::class)->findOneBy([]);
        $orgUser = $em->getRepository(OrgUser::class)->findOneBy(['organization' => $org]);

        if (!$orgUser) {
            $orgUser = new OrgUser();
            $orgUser->setOrganization($org);
            $orgUser->setFirstName('SessionStudent');
            $orgUser->setLastName('Test');
            $orgUser->setEmail('session.student@acme.com');
            $orgUser->setPin('C777');
            $orgUser->setPinHash(password_hash('C777', PASSWORD_BCRYPT));
            $em->persist($orgUser);
            $em->flush();
        }

        $session = new WorkSession();
        $session->setOrgUser($orgUser);
        $session->setOrganization($org);
        $session->setStartedAt(new \DateTime('-35 minutes'));
        $session->setStartMethod('kiosk');
        $em->persist($session);
        $em->flush();

        // 1. GET /admin/active-sessions
        $client->request('GET', '/admin/active-sessions');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', $orgUser->getFirstName());

        // 2. Force End session
        $client->request('POST', '/admin/active-sessions/force-end/' . $session->getId());
        $this->assertResponseRedirects('/admin/active-sessions');

        $em->clear();
        $endedSession = $em->getRepository(WorkSession::class)->find($session->getId());
        $this->assertNotNull($endedSession->getEndedAt());
        $this->assertEquals('Admin Force End', $endedSession->getEndMethod());

        // Cleanup
        $em->remove($endedSession);
        $em->flush();
    }

    public function testAdminOrganizationsPageAndEditLimits(): void
    {
        [$client, $em] = $this->getAdminClient();

        $client->request('GET', '/admin/organizations');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('table');

        $org = $em->getRepository(Organization::class)->findOneBy([]);

        // Update limits with matching plan_id so overrides are applied
        $client->request('POST', '/admin/organizations/' . $org->getId() . '/edit-limits', [
            'plan_id' => $org->getPlan() ? $org->getPlan()->getId() : '',
            'max_orgUsers_override' => 150,
            'max_managers_override' => 10,
            'max_categories_override' => 15,
            'session_limit_hours_override' => 8
        ]);

        $this->assertResponseRedirects('/admin/organizations');
        $client->followRedirect();
        $this->assertSelectorExists('.alert-success');

        $em->clear();
        $updatedOrg = $em->getRepository(Organization::class)->find($org->getId());
        $this->assertEquals(150, $updatedOrg->getMaxOrgUsersOverride());
        $this->assertEquals(10, $updatedOrg->getMaxManagersOverride());
    }

    public function testAdminPlansPageListAndEdit(): void
    {
        [$client, $em] = $this->getAdminClient();

        // 1. GET /admin/plans
        $client->request('GET', '/admin/plans');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Global Plan Tier Settings');

        // 2. POST /admin/plans (Update plan parameters)
        $basicPlan = $em->getRepository(Plan::class)->findOneBy([]);
        if ($basicPlan) {
            $client->request('POST', '/admin/plans', [
                'plan_id' => $basicPlan->getId(),
                'max_orgUsers' => $basicPlan->getMaxOrgUsers(),
                'max_managers' => $basicPlan->getMaxManagers(),
                'max_fields' => $basicPlan->getMaxFields(),
                'max_categories' => $basicPlan->getMaxCategories(),
                'session_limit_hours' => $basicPlan->getSessionLimitHours(),
                'retention_days' => $basicPlan->getRetentionDays(),
                'exports_enabled' => $basicPlan->isExportsEnabled() ? 'yes' : 'no',
                'price_monthly' => (string)$basicPlan->getPriceMonthly(),
                'stripe_price_id' => (string)$basicPlan->getStripePriceId()
            ]);

            $this->assertResponseRedirects('/admin/plans');
            $client->followRedirect();
            $this->assertResponseIsSuccessful();
        }
    }

    public function testAdminBillingTabsAndFilters(): void
    {
        [$client] = $this->getAdminClient();

        // Overview tab
        $client->request('GET', '/admin/billing', ['tab' => 'overview']);
        $this->assertResponseIsSuccessful();

        // Subscriptions tab with search query
        $client->request('GET', '/admin/billing', ['tab' => 'subscriptions', 'q' => 'acme']);
        $this->assertResponseIsSuccessful();

        // History tab
        $client->request('GET', '/admin/billing', ['tab' => 'history']);
        $this->assertResponseIsSuccessful();

        // Failed invoices tab
        $client->request('GET', '/admin/billing', ['tab' => 'failed']);
        $this->assertResponseIsSuccessful();
    }

    public function testAdminEmailsListAndEditTemplate(): void
    {
        [$client] = $this->getAdminClient();

        // 1. GET /admin/emails
        $client->request('GET', '/admin/emails');
        $this->assertResponseIsSuccessful();

        // 2. GET /admin/emails/edit/expiry_warning
        $client->request('GET', '/admin/emails/edit/expiry_warning');
        $this->assertResponseIsSuccessful();

        // 3. POST /admin/emails/edit/expiry_warning
        $content = file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/templates/email/expiry_warning.html.twig');
        $client->request('POST', '/admin/emails/edit/expiry_warning', [
            'content' => $content
        ]);

        $this->assertResponseRedirects('/admin/emails');
        $client->followRedirect();
        $this->assertSelectorExists('.alert-success');
    }

    public function testAdminSettingsAllTabs(): void
    {
        [$client] = $this->getAdminClient();

        // GET /admin/settings
        $client->request('GET', '/admin/settings');
        $this->assertResponseIsSuccessful();

        // POST /admin/settings (Update site configurations)
        $client->request('POST', '/admin/settings', [
            'site_name' => 'CenterTime QA Platform',
            'support_email' => 'support@centertime.us',
            'new_signups_enabled' => '1',
            'allow_pdf_kiosk_posters' => '1',
            'privacy_policy_text' => 'Updated Privacy Policy Content',
            'terms_of_service_text' => 'Updated Terms of Service Content'
        ]);

        $this->assertResponseRedirects('/admin/settings');
        $client->followRedirect();
        $this->assertSelectorExists('.alert-success');
    }

    public function testAdminDemoLeadsPage(): void
    {
        [$client] = $this->getAdminClient();

        $client->request('GET', '/admin/demo-leads');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Demo Leads');
    }

    public function testAdminManagersPageAndEdit(): void
    {
        [$client, $em] = $this->getAdminClient();

        // 1. GET /admin/managers
        $client->request('GET', '/admin/managers');
        $this->assertResponseIsSuccessful();

        // 2. POST /admin/managers/{id}/edit
        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        if ($manager) {
            $client->request('POST', '/admin/managers/' . $manager->getId() . '/edit', [
                'first_name' => 'AcmeAdminManager',
                'last_name' => 'AcmeLastName',
                'email' => 'manager@acme.com',
                'phone' => '+15551112222',
                'role' => 'ROLE_ORG_MANAGER'
            ]);

            $this->assertResponseRedirects('/admin/managers');
            $client->followRedirect();
            $this->assertSelectorExists('.alert-success');
        }
    }

    public function testAdminAuditLogsPageFilters(): void
    {
        [$client, $em] = $this->getAdminClient();

        $org = $em->getRepository(Organization::class)->findOneBy([]);

        // 1. GET /admin/audit-logs without parameters
        $client->request('GET', '/admin/audit-logs');
        $this->assertResponseIsSuccessful();

        // 2. GET /admin/audit-logs with org and action filters
        $client->request('GET', '/admin/audit-logs', [
            'org' => $org->getId(),
            'action' => 'LOGIN'
        ]);
        $this->assertResponseIsSuccessful();
    }
}
