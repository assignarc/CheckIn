<?php

namespace App\Service;

use App\Entity\Manager;
use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class EmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private EntityManagerInterface $em
    ) {
    }

    public function sendWelcomeEmail(Manager $manager): void
    {
        $html = $this->twig->render('email/welcome.html.twig', [
            'manager' => $manager,
            'org' => $manager->getOrganization()
        ]);

        $email = (new Email())
            ->from('support@centertime.us')
            ->to($manager->getEmail())
            ->subject('Welcome to CenterTime! Verify Your Account')
            ->html($html);

        $this->mailer->send($email);
        $this->logEmailEvent($manager->getOrganization(), 'EMAIL_WELCOME_SENT', [
            'email_type' => 'Welcome Account Verification',
            'email_kind' => 'Welcome',
            'to' => $manager->getEmail()
        ]);
    }

    public function sendSubscriptionRenewalNotice(Manager $manager, string $amount, string $renewalDate): void
    {
        $html = $this->twig->render('email/renewal_success.html.twig', [
            'manager' => $manager,
            'amount' => $amount,
            'renewalDate' => $renewalDate
        ]);

        $email = (new Email())
            ->from('billing@centertime.us')
            ->to($manager->getEmail())
            ->subject('CenterTime Subscription Successfully Renewed')
            ->html($html);

        $this->mailer->send($email);
        $this->logEmailEvent($manager->getOrganization(), 'EMAIL_RENEWAL_SUCCESS_SENT', [
            'email_type' => 'Subscription Renewal Success',
            'email_kind' => 'Renewal Notice',
            'to' => $manager->getEmail(),
            'amount' => $amount
        ]);
    }

    public function sendSubscriptionExpiryWarning(Manager $manager): void
    {
        $html = $this->twig->render('email/expiry_warning.html.twig', [
            'manager' => $manager,
            'org' => $manager->getOrganization()
        ]);

        $email = (new Email())
            ->from('billing@centertime.us')
            ->to($manager->getEmail())
            ->subject('Action Required: CenterTime Subscription Payment Failed')
            ->html($html);

        $this->mailer->send($email);
        $this->logEmailEvent($manager->getOrganization(), 'EMAIL_EXPIRY_WARNING_SENT', [
            'email_type' => 'Subscription Payment Failed Warning',
            'email_kind' => 'Payment Warning',
            'to' => $manager->getEmail()
        ]);
    }

    public function sendSubscriptionUpcomingInvoiceNotice(Manager $manager, string $amount, string $dueDate): void
    {
        $html = $this->twig->render('email/upcoming_invoice.html.twig', [
            'manager' => $manager,
            'amount' => $amount,
            'dueDate' => $dueDate
        ]);

        $email = (new Email())
            ->from('billing@centertime.us')
            ->to($manager->getEmail())
            ->subject('Upcoming Renewal Notice - CenterTime')
            ->html($html);

        $this->mailer->send($email);
        $this->logEmailEvent($manager->getOrganization(), 'EMAIL_UPCOMING_INVOICE_SENT', [
            'email_type' => 'Subscription Upcoming Renewal Notice',
            'email_kind' => 'Upcoming Invoice',
            'to' => $manager->getEmail(),
            'amount' => $amount
        ]);
    }

    private function logEmailEvent(\App\Entity\Organization $org, string $action, array $details): void
    {
        $log = new AuditLog();
        $log->setOrganization($org);
        $log->setAction($action);
        $log->setDetailsJson($details);
        $log->setIpAddress('127.0.0.1');
        $log->setUserAgent('Mailer Service');
        $this->em->persist($log);
        $this->em->flush();
    }
}
