<?php

namespace App\Service\Stripe\Handler;

use App\Entity\AuditLog;
use App\Entity\Organization;
use App\Entity\PaymentHistory;
use App\Entity\Plan;
use App\Service\EmailService;
use App\Service\Stripe\StripeEventHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class CheckoutSessionCompletedHandler implements StripeEventHandlerInterface
{
    public function supports(string $eventType): bool
    {
        return $eventType === 'checkout.session.completed';
    }

    public function handle(object $event, Request $request, EntityManagerInterface $em, EmailService $emailService, bool $isTestEnv, bool $isPlaceholderSecret): void
    {
        $session = $event->data->object;
        $stripeSubId = $session->subscription ?? 'mock_sub_id';
        $customerId = $session->customer ?? null;

        if (!$customerId) {
            return;
        }

        $org = $em->getRepository(Organization::class)->findOneBy(['stripeCustomerId' => $customerId]);
        if (!$org) {
            return;
        }

        $org->setStripeSubscriptionId($stripeSubId);
        $org->setIsSubscriptionActive(true);

        if (!$isTestEnv && !$isPlaceholderSecret && $stripeSubId && $stripeSubId !== 'mock_sub_id') {
            try {
                $existingSubs = \Stripe\Subscription::all([
                    'customer' => $customerId,
                    'status' => 'active',
                ]);
                foreach ($existingSubs->data as $sub) {
                    if ($sub->id !== $stripeSubId) {
                        try {
                            $sub->cancel();

                            $log = new AuditLog();
                            $log->setOrganization($org);
                            $log->setAction('STRIPE_PREVIOUS_SUBSCRIPTION_CANCELED');
                            $log->setDetailsJson([
                                'canceled_subscription_id' => $sub->id,
                                'active_subscription_id' => $stripeSubId,
                                'reason' => 'Canceled previous duplicate subscription on checkout completion'
                            ]);
                            $log->setIpAddress($request->getClientIp());
                            $log->setUserAgent($request->headers->get('User-Agent'));
                            $em->persist($log);
                        } catch (\Throwable $e) {
                            // Silently ignore if already canceled
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Silently ignore if lookup fails
            }
        }

        $purchasedPlanName = is_array($session->metadata) ? ($session->metadata['plan'] ?? null) : ($session->metadata->plan ?? null);
        if ($purchasedPlanName) {
            $purchasedPlan = $em->getRepository(Plan::class)->findOneBy(['name' => $purchasedPlanName]);
            if ($purchasedPlan) {
                $org->setPlan($purchasedPlan);
                if ($purchasedPlan->hasSecureKiosk()) {
                    $org->setAccessMode('secure');
                }
            }
        }

        $payment = new PaymentHistory();
        $payment->setOrganization($org);
        $payment->setStripeInvoiceId($session->invoice ?? ('sub_' . bin2hex(random_bytes(8))));
        $payment->setAmount(number_format(($session->amount_total ?? 0) / 100, 2, '.', ''));
        $payment->setStatus('paid');
        $payment->setPaidAt(new \DateTime());
        $payment->setPeriodStart(new \DateTime());
        $payment->setPeriodEnd(new \DateTime('+1 month'));
        $payment->setDescription(ucfirst($org->getPlan()->getName()) . ' Plan Subscription Activated');
        $em->persist($payment);

        $log = new AuditLog();
        $log->setOrganization($org);
        $log->setAction('STRIPE_CHECKOUT_COMPLETED');
        $log->setDetailsJson([
            'stripe_customer_id' => $customerId,
            'stripe_subscription_id' => $stripeSubId,
            'plan' => $org->getPlan()->getName(),
        ]);
        $log->setIpAddress($request->getClientIp());
        $log->setUserAgent($request->headers->get('User-Agent'));
        $em->persist($log);

        $em->flush();
    }
}
