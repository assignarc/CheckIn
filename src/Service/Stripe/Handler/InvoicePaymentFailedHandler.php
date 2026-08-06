<?php

namespace App\Service\Stripe\Handler;

use App\Entity\AuditLog;
use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\Plan;
use App\Service\EmailService;
use App\Service\Stripe\StripeEventHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class InvoicePaymentFailedHandler implements StripeEventHandlerInterface
{
    public function supports(string $eventType): bool
    {
        return $eventType === 'invoice.payment_failed';
    }

    public function handle(object $event, Request $request, EntityManagerInterface $em, EmailService $emailService, bool $isTestEnv, bool $isPlaceholderSecret): void
    {
        $invoice = $event->data->object;
        $customerId = $invoice->customer ?? null;

        if (!$customerId) {
            return;
        }

        $org = $em->getRepository(Organization::class)->findOneBy(['stripeCustomerId' => $customerId]);
        if (!$org) {
            return;
        }

        $basicPlan = $em->getRepository(Plan::class)->findOneBy(['name' => 'basic']);
        if ($basicPlan) {
            $org->setPlan($basicPlan);
            $org->clearOverrides();
            $org->setAccessMode('open');
        }
        $org->setPendingPlan(null);
        $org->setPendingPlanEffectiveAt(null);
        $org->setIsSubscriptionActive(true);

        $log1 = new AuditLog();
        $log1->setOrganization($org);
        $log1->setAction('STRIPE_PAYMENT_FAILED');
        $log1->setDetailsJson([
            'invoice_id' => $invoice->id ?? '',
            'amount_due' => number_format(($invoice->amount_due ?? 0) / 100, 2, '.', '')
        ]);
        $log1->setIpAddress($request->getClientIp());
        $log1->setUserAgent($request->headers->get('User-Agent'));
        $em->persist($log1);

        $log2 = new AuditLog();
        $log2->setOrganization($org);
        $log2->setAction('STRIPE_PLAN_DOWNGRADED_BASIC');
        $log2->setDetailsJson([
            'reason' => 'Monthly subscription payment failed at Stripe',
            'new_plan' => 'basic'
        ]);
        $log2->setIpAddress($request->getClientIp());
        $log2->setUserAgent($request->headers->get('User-Agent'));
        $em->persist($log2);

        $em->flush();

        $manager = $em->getRepository(Manager::class)->findOneBy(['organization' => $org, 'role' => 'ROLE_ORG_MANAGER']);
        if ($manager) {
            try {
                $emailService->sendSubscriptionExpiryWarning($manager);
            } catch (\Exception $e) {
                // Silently ignore mailer issues in webhook
            }
        }
    }
}
