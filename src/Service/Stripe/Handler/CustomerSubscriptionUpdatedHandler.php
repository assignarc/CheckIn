<?php

namespace App\Service\Stripe\Handler;

use App\Entity\AuditLog;
use App\Entity\Organization;
use App\Entity\Plan;
use App\Service\EmailService;
use App\Service\Stripe\StripeEventHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class CustomerSubscriptionUpdatedHandler implements StripeEventHandlerInterface
{
    public function supports(string $eventType): bool
    {
        return $eventType === 'customer.subscription.updated';
    }

    public function handle(object $event, Request $request, EntityManagerInterface $em, EmailService $emailService, bool $isTestEnv, bool $isPlaceholderSecret): void
    {
        $subscription = $event->data->object;
        $customerId = $subscription->customer ?? null;
        $status = $subscription->status ?? '';

        if (!$customerId) {
            return;
        }

        $org = $em->getRepository(Organization::class)->findOneBy(['stripeCustomerId' => $customerId]);
        if (!$org) {
            return;
        }

        if ($status === 'canceled' || $status === 'unpaid') {
            $basicPlan = $em->getRepository(Plan::class)->findOneBy(['name' => 'basic']);
            if ($basicPlan) {
                $org->setPlan($basicPlan);
                $org->clearOverrides();
                $org->setAccessMode('open');
            }
            $org->setStripeSubscriptionId(null);
            $org->setIsSubscriptionActive(true);

            $log = new AuditLog();
            $log->setOrganization($org);
            $log->setAction('STRIPE_SUBSCRIPTION_CANCELLED');
            $log->setDetailsJson([
                'stripe_subscription_id' => $subscription->id ?? '',
                'status' => $status,
                'new_plan' => 'basic'
            ]);
            $log->setIpAddress($request->getClientIp());
            $log->setUserAgent($request->headers->get('User-Agent'));
            $em->persist($log);

            $em->flush();
        } elseif (!empty($subscription->cancel_at_period_end)) {
            $log = new AuditLog();
            $log->setOrganization($org);
            $log->setAction('STRIPE_SUBSCRIPTION_CANCEL_SCHEDULED');
            $log->setDetailsJson([
                'stripe_subscription_id' => $subscription->id ?? '',
                'cancel_at' => $subscription->cancel_at ?? null
            ]);
            $log->setIpAddress($request->getClientIp());
            $log->setUserAgent($request->headers->get('User-Agent'));
            $em->persist($log);

            $em->flush();
        }
    }
}
