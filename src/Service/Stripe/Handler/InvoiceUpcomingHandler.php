<?php

namespace App\Service\Stripe\Handler;

use App\Entity\Manager;
use App\Entity\Organization;
use App\Service\EmailService;
use App\Service\Stripe\StripeEventHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class InvoiceUpcomingHandler implements StripeEventHandlerInterface
{
    public function supports(string $eventType): bool
    {
        return $eventType === 'invoice.upcoming';
    }

    public function handle(object $event, Request $request, EntityManagerInterface $em, EmailService $emailService, bool $isTestEnv, bool $isPlaceholderSecret): void
    {
        $invoice = $event->data->object;
        $customerId = $invoice->customer ?? null;
        $amountDue = number_format(($invoice->amount_due ?? 0) / 100, 2, '.', '');
        $dueDate = $invoice->next_payment_attempt ? (new \DateTime('@' . $invoice->next_payment_attempt))->format('M d, Y') : (new \DateTime('+7 days'))->format('M d, Y');

        if (!$customerId) {
            return;
        }

        $org = $em->getRepository(Organization::class)->findOneBy(['stripeCustomerId' => $customerId]);
        if (!$org) {
            return;
        }

        $manager = $em->getRepository(Manager::class)->findOneBy(['organization' => $org, 'role' => 'ROLE_ORG_MANAGER']);
        if ($manager) {
            try {
                $emailService->sendSubscriptionUpcomingInvoiceNotice($manager, $amountDue, $dueDate);
            } catch (\Exception $e) {
                // Silently ignore mailer issues in webhook
            }
        }
    }
}
