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
    private string $adminEmail;

    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private EntityManagerInterface $em,
        ?\Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface $params = null
    ) {
        $this->adminEmail = $params && $params->has('admin_email') ? (string) $params->get('admin_email') : 'admin@centertime.us';
    }

    public function sendContactNotification(\App\Entity\ContactMessage $contactMessage): void
    {
        $targetEmail = $this->adminEmail ?: 'admin@centertime.us';

        $body = sprintf(
            "New Contact Us Submission Received:\n\nName: %s\nEmail: %s\nSubject: %s\nSubmitted: %s\n\nMessage:\n%s",
            $contactMessage->getName(),
            $contactMessage->getEmail(),
            $contactMessage->getSubject() ?: '(No Subject)',
            $contactMessage->getCreatedAt()->format('Y-m-d H:i:s'),
            $contactMessage->getMessage()
        );

        $email = (new Email())
            ->from('noreply@centertime.us')
            ->to($targetEmail)
            ->subject('New Website Contact Inquiry: ' . ($contactMessage->getSubject() ?: 'General Inquiry'))
            ->text($body);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            // Log error silently if mail transport fails in dev mode
        }
    }

    public function sendDemoLeadNotification(\App\Entity\DemoLead $lead): void
    {
        $targetEmail = $this->adminEmail ?: 'admin@centertime.us';

        $body = sprintf(
            "New Demo Request (Lead) Received:\n\nName: %s\nEmail: %s\nPhone: %s\nCompany: %s\nSubmitted: %s",
            $lead->getName(),
            $lead->getEmail(),
            $lead->getPhone() ?: 'N/A',
            $lead->getCompany() ?: 'N/A',
            $lead->getCreatedAt()->format('Y-m-d H:i:s')
        );

        $email = (new Email())
            ->from('noreply@centertime.us')
            ->to($targetEmail)
            ->subject('New Demo Lead Request: ' . $lead->getName())
            ->text($body);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            // Log error silently if mail transport fails in dev mode
        }
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

    public function sendSubscriptionCancelledNotice(Manager $manager): void
    {
        $html = $this->twig->render('email/expiry_warning.html.twig', [
            'manager' => $manager,
            'org' => $manager->getOrganization(),
            'custom_message' => 'Your subscription has been cancelled and your account has been placed on the Basic (Free) plan.'
        ]);

        $email = (new Email())
            ->from('billing@centertime.us')
            ->to($manager->getEmail())
            ->subject('CenterTime Subscription Cancelled - Downgraded to Basic Plan')
            ->html($html);

        $this->mailer->send($email);
        $this->logEmailEvent($manager->getOrganization(), 'EMAIL_SUBSCRIPTION_CANCELLED_SENT', [
            'email_type' => 'Subscription Cancelled Notice',
            'email_kind' => 'Cancellation Notice',
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

    public function sendPlanChangeScheduledNotice(Manager $manager, string $targetPlanName, string $effectiveDate): void
    {
        $html = $this->twig->render('email/expiry_warning.html.twig', [
            'manager' => $manager,
            'org' => $manager->getOrganization(),
            'custom_message' => sprintf('Your request to switch to the %s plan has been scheduled for %s. You will retain your current plan access until that date.', ucfirst($targetPlanName), $effectiveDate)
        ]);

        $email = (new Email())
            ->from('billing@centertime.us')
            ->to($manager->getEmail())
            ->subject(sprintf('CenterTime - Plan Change Scheduled for %s', $effectiveDate))
            ->html($html);

        $this->mailer->send($email);
        $this->logEmailEvent($manager->getOrganization(), 'EMAIL_PLAN_CHANGE_SCHEDULED_SENT', [
            'target_plan' => $targetPlanName,
            'effective_date' => $effectiveDate,
            'to' => $manager->getEmail()
        ]);
    }

    public function sendPlanChangeAppliedNotice(Manager $manager, string $newPlanName): void
    {
        $html = $this->twig->render('email/renewal_success.html.twig', [
            'manager' => $manager,
            'org' => $manager->getOrganization(),
            'custom_message' => sprintf('Your organization has successfully transitioned to the %s plan.', ucfirst($newPlanName))
        ]);

        $email = (new Email())
            ->from('billing@centertime.us')
            ->to($manager->getEmail())
            ->subject(sprintf('CenterTime - %s Plan Now Active', ucfirst($newPlanName)))
            ->html($html);

        $this->mailer->send($email);
        $this->logEmailEvent($manager->getOrganization(), 'EMAIL_PLAN_CHANGE_APPLIED_SENT', [
            'new_plan' => $newPlanName,
            'to' => $manager->getEmail()
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
