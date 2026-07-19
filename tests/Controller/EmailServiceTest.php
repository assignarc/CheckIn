<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EmailServiceTest extends WebTestCase
{
    /**
     * Test transactional email dispatching, template formatting, and automatic audit logs
     */
    public function testEmailServiceDeliveries(): void
    {
        $client = static::createClient();
        
        // Boot kernel
        $client->request('GET', '/login');
        $container = static::getContainer();
        
        // Reset the mailer message logger
        if ($container->has('mailer.message_logger_listener')) {
            $container->get('mailer.message_logger_listener')->reset();
        }

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $emailService = $container->get(\App\Service\EmailService::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $this->assertNotNull($manager);

        // 1. Test Welcome Email
        $emailService->sendWelcomeEmail($manager);
        
        $email1 = $this->getMailerMessage(0);
        $this->assertEmailHeaderSame($email1, 'subject', 'Welcome to CenterTime! Verify Your Account');
        $this->assertEmailAddressContains($email1, 'to', $manager->getEmail());
        $this->assertEmailHtmlBodyContains($email1, 'Welcome to CenterTime');

        $logWelcome = $em->getRepository(AuditLog::class)->findOneBy([
            'organization' => $manager->getOrganization(),
            'action' => 'EMAIL_WELCOME_SENT'
        ]);
        $this->assertNotNull($logWelcome);

        // 2. Test Renewal Success Email
        $emailService->sendSubscriptionRenewalNotice($manager, '9.99', 'Aug 15, 2026');

        $email2 = $this->getMailerMessage(2);
        $this->assertEmailHeaderSame($email2, 'subject', 'CenterTime Subscription Successfully Renewed');
        $this->assertEmailHtmlBodyContains($email2, '$9.99');
        $this->assertEmailHtmlBodyContains($email2, 'Aug 15, 2026');

        $logRenewal = $em->getRepository(AuditLog::class)->findOneBy([
            'organization' => $manager->getOrganization(),
            'action' => 'EMAIL_RENEWAL_SUCCESS_SENT'
        ]);
        $this->assertNotNull($logRenewal);

        // 3. Test Expiry Warning Email
        $emailService->sendSubscriptionExpiryWarning($manager);

        $email3 = $this->getMailerMessage(4);
        $this->assertEmailHeaderSame($email3, 'subject', 'Action Required: CenterTime Subscription Payment Failed');
        $this->assertEmailHtmlBodyContains($email3, 'locked');

        $logExpiry = $em->getRepository(AuditLog::class)->findOneBy([
            'organization' => $manager->getOrganization(),
            'action' => 'EMAIL_EXPIRY_WARNING_SENT'
        ]);
        $this->assertNotNull($logExpiry);

        // 4. Test Upcoming Invoice Notice Email
        $emailService->sendSubscriptionUpcomingInvoiceNotice($manager, '29.00', 'Aug 22, 2026');

        $email4 = $this->getMailerMessage(6);
        $this->assertEmailHeaderSame($email4, 'subject', 'Upcoming Renewal Notice - CenterTime');
        $this->assertEmailHtmlBodyContains($email4, '$29.00');
        $this->assertEmailHtmlBodyContains($email4, 'Aug 22, 2026');

        $logUpcoming = $em->getRepository(AuditLog::class)->findOneBy([
            'organization' => $manager->getOrganization(),
            'action' => 'EMAIL_UPCOMING_INVOICE_SENT'
        ]);
        $this->assertNotNull($logUpcoming);
    }
}
