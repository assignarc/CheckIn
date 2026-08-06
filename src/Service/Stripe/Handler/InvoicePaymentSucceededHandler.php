<?php

namespace App\Service\Stripe\Handler;

use App\Entity\AuditLog;
use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\PaymentHistory;
use App\Service\EmailService;
use App\Service\Stripe\StripeEventHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class InvoicePaymentSucceededHandler implements StripeEventHandlerInterface
{
    public function supports(string $eventType): bool
    {
        return $eventType === 'invoice.payment_succeeded';
    }

    public function handle(object $event, Request $request, EntityManagerInterface $em, EmailService $emailService, bool $isTestEnv, bool $isPlaceholderSecret): void
    {
        $invoice = $event->data->object;
        $customerId = $invoice->customer ?? null;
        $amountPaid = number_format(($invoice->amount_paid ?? 0) / 100, 2, '.', '');

        if (!$customerId) {
            return;
        }

        $org = $em->getRepository(Organization::class)->findOneBy(['stripeCustomerId' => $customerId]);
        if (!$org) {
            return;
        }

        if ($org->getPendingPlan() && $org->getPendingPlan()->getName() !== 'basic') {
            $org->setPlan($org->getPendingPlan());
            $org->setPendingPlan(null);
            $org->setPendingPlanEffectiveAt(null);
        }

        $payment = new PaymentHistory();
        $payment->setOrganization($org);
        $payment->setStripeInvoiceId($invoice->id ?? ('in_' . bin2hex(random_bytes(8))));
        $payment->setAmount($amountPaid);
        $payment->setStatus('paid');
        $payment->setPaidAt(new \DateTime());
        $payment->setPeriodStart(new \DateTime());
        $payment->setPeriodEnd(new \DateTime('+1 month'));
        $payment->setDescription(ucfirst($org->getPlan()->getName()) . ' Plan Subscription Renewal');
        $em->persist($payment);

        $org->setIsSubscriptionActive(true);

        $log = new AuditLog();
        $log->setOrganization($org);
        $log->setAction('STRIPE_PAYMENT_SUCCESS');
        $log->setDetailsJson([
            'invoice_id' => $invoice->id ?? '',
            'amount' => $amountPaid,
            'plan' => $org->getPlan()->getName()
        ]);
        $log->setIpAddress($request->getClientIp());
        $log->setUserAgent($request->headers->get('User-Agent'));
        $em->persist($log);

        $em->flush();

        $manager = $em->getRepository(Manager::class)->findOneBy(['organization' => $org, 'role' => 'ROLE_ORG_MANAGER']);
        if ($manager) {
            try {
                $emailService->sendPlanChangeAppliedNotice($manager, $org->getPlan()->getName());
            } catch (\Exception $e) {
                // Silently log or ignore mailer issues during webhooks
            }
        }
    }
}
