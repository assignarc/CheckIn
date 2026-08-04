<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\PaymentHistory;
use App\Entity\AuditLog;
use App\Entity\DemoLead;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OrganizationArchiveTest extends WebTestCase
{
    public function testFullArchiveAndDeletionLifecycle(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // Generate dynamic unique slug and email to prevent duplicate database constraints
        $uniqueId = uniqid('temp_', false);
        $slug = 'org-' . $uniqueId;
        $managerEmail = $uniqueId . '@test.com';
        $adminEmail = 'superadmin_' . $uniqueId . '@test.com';

        $admin = new Manager();
        $admin->setEmail($adminEmail);
        $admin->setFirstName('Super');
        $admin->setLastName('Admin');
        $admin->setPasswordHash('$2y$13$dummyhashedpassword');
        $admin->setRole('ROLE_SYSTEM_ADMIN');
        $em->persist($admin);
        $em->flush();

        $plan = $em->getRepository(\App\Entity\Plan::class)->findOneBy(['name' => 'gold']);

        // 1. Create a clean isolated temporary organization
        $org = new Organization();
        $org->setName('Temp Test Org ' . $uniqueId);
        $org->setSlug($slug);
        $org->setPlan($plan);
        $org->setCheckInLinkToken('token_' . $uniqueId);
        $org->setIsArchived(false);
        $org->setIsSubscriptionActive(true);
        $org->setStripeCustomerId('cus_' . $uniqueId);
        $em->persist($org);

        // Create manager
        $manager = new Manager();
        $manager->setEmail($managerEmail);
        $manager->setFirstName('Temp');
        $manager->setLastName('Manager');
        $manager->setRole('ROLE_ORG_MANAGER');
        $manager->setOrganization($org);
        $manager->setIsActive(true);
        $manager->setPasswordHash(password_hash('manager123', PASSWORD_BCRYPT));
        $em->persist($manager);

        $em->flush();

        // 2. Simulate failed Stripe payment webhook
        $payloadFailed = json_encode([
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'customer' => 'cus_' . $uniqueId
                ]
            ]
        ]);
        $client->request('POST', '/webhooks/stripe', [], [], [], $payloadFailed);
        $this->assertResponseIsSuccessful();

        $em->clear();
        $org = $em->getRepository(Organization::class)->findOneBy(['slug' => $slug]);
        $org->setIsSubscriptionActive(false);
        $em->flush();
        $this->assertFalse($org->isArchived());

        // Create a past payment that expired yesterday
        $payment = new PaymentHistory();
        $payment->setOrganization($org);
        $payment->setStripeInvoiceId('in_expired_' . $uniqueId);
        $payment->setAmount('29.00');
        $payment->setStatus('paid');
        $payment->setPaidAt(new \DateTime('-31 days'));
        $payment->setPeriodStart(new \DateTime('-31 days'));
        $payment->setPeriodEnd(new \DateTime('-1 day'));
        $em->persist($payment);
        $em->flush();

        // 3. Trigger cron job (which runs sweep and checks expired unpaid periods)
        $cronToken = $container->getParameter('cron_secret_token');
        $client->request('GET', '/cron/run?token=' . $cronToken);
        $this->assertResponseIsSuccessful();

        $em->clear();
        $org = $em->getRepository(Organization::class)->findOneBy(['slug' => $slug]);
        // Now it must be automatically archived since its paid period is in the past!
        $this->assertTrue($org->isArchived());

        // Assert auto-archival event was logged
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'organization' => $org,
            'action' => 'ORGANIZATION_AUTO_ARCHIVED_PAYMENT_FAILED'
        ]);
        $this->assertNotNull($log);

        // 4. Test that archived organization blocks kiosk access
        $client->request('GET', "/org/{$slug}/kiosk/{$org->getCheckInLinkToken()}");
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-danger', 'This organization has been archived.');

        // 5. Test that archived organization restricts manager access to non-billing paths
        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => $managerEmail]);
        $client->loginUser($manager);
        $client->request('GET', '/manager/users');
        $this->assertResponseRedirects('/manager/billing');

        // Check flash warning is stored/displayed
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'Organization Archived:');

        // 6. Admin toggles organization out of archive mode
        $client->getCookieJar()->clear();
        $admin = $em->getRepository(Manager::class)->findOneBy(['email' => $adminEmail]);
        $client->loginUser($admin);
        $client->request('POST', "/admin/organizations/archive-toggle/{$org->getId()}");
        $this->assertResponseRedirects('/admin/organizations');

        $em->clear();
        $org = $em->getRepository(Organization::class)->findOneBy(['slug' => $slug]);
        $this->assertFalse($org->isArchived()); // unarchived successfully!

        // 7. Admin deletes organization (creating JSON ZIP backup and deleting DB records)
        $orgId = $org->getId();
        $client->request('POST', "/admin/organizations/delete/{$orgId}");
        $this->assertResponseRedirects('/admin/organizations');

        // Verify database records are permanently deleted
        $em->clear();
        $deletedOrg = $em->getRepository(Organization::class)->find($orgId);
        $this->assertNull($deletedOrg);

        // Verify backup zip exists in var/archives
        $projectDir = $container->getParameter('kernel.project_dir');
        $zipFiles = glob($projectDir . "/var/archives/{$slug}_*.zip");
        $this->assertNotEmpty($zipFiles);
        $zipPath = $zipFiles[0];
        $this->assertFileExists($zipPath);

        // Clean up backup file
        unlink($zipPath);

        // 8. Admin deletes a demo lead
        $lead = new DemoLead();
        $lead->setName('Test Lead');
        $lead->setEmail('lead@test.com');
        $em->persist($lead);
        $em->flush();

        $leadId = $lead->getId();
        $client->request('POST', "/admin/demo-leads/delete/{$leadId}");
        $this->assertResponseRedirects('/admin/demo-leads');

        $em->clear();
        $deletedLead = $em->getRepository(DemoLead::class)->find($leadId);
        $this->assertNull($deletedLead);
    }
}
