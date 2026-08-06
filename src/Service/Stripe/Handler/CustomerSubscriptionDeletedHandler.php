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

class CustomerSubscriptionDeletedHandler implements StripeEventHandlerInterface
{
    public function supports(string $eventType): bool
    {
        return $eventType === 'customer.subscription.deleted';
    }

    public function handle(object $event, Request $request, EntityManagerInterface $em, EmailService $emailService, bool $isTestEnv, bool $isPlaceholderSecret): void
    {
        $subscription = $event->data->object;
        $customerId = $subscription->customer ?? null;

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
        $org->setStripeSubscriptionId(null);
        $org->setIsSubscriptionActive(true);

        $log = new AuditLog();
        $log->setOrganization($org);
        $log->setAction('STRIPE_SUBSCRIPTION_CANCELLED');
        $log->setDetailsJson([
            'stripe_subscription_id' => $subscription->id ?? '',
            'reason' => 'Subscription cancelled at Stripe',
            'new_plan' => 'basic'
        ]);
        $log->setIpAddress($request->getClientIp());
        $log->setUserAgent($request->headers->get('User-Agent'));
        $em->persist($log);

        $em->flush();

        $manager = $em->getRepository(Manager::class)->findOneBy(['organization' => $org, 'role' => 'ROLE_ORG_MANAGER']);
        if ($manager) {
            try {
                $emailService->sendPlanChangeAppliedNotice($manager, 'basic');
            } catch (\Exception $e) {
                // Silently ignore mailer failure
            }
        }
    }
}
