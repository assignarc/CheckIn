<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\Plan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StripeSubscriptionWorkflowTest extends WebTestCase
{
    private function createTestOrganization(EntityManagerInterface $em, string $planName = 'basic'): array
    {
        $uniq = uniqid();
        $plan = $em->getRepository(Plan::class)->findOneBy(['name' => $planName]);

        $org = new Organization();
        $org->setName('Test Workflow Org ' . $uniq);
        $org->setSlug('workflow-org-' . $uniq);
        $org->setPlan($plan);
        $org->setTimezone('America/New_York');
        $org->setStripeCustomerId('cus_test_' . $uniq);
        $org->setStripeSubscriptionId('sub_test_' . $uniq);
        $org->setIsSubscriptionActive(true);
        $em->persist($org);

        $manager = new Manager();
        $manager->setOrganization($org);
        $manager->setFirstName('Workflow');
        $manager->setLastName('Manager');
        $manager->setEmail('manager.' . $uniq . '@example.com');
        $manager->setPasswordHash('$2y$13$dummyhashedpassword');
        $manager->setRole('ROLE_ORG_MANAGER');
        $em->persist($manager);

        $em->flush();

        return [$org, $manager];
    }

    public function testCase1BasicToPaidImmediateUpgrade(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$org, $manager] = $this->createTestOrganization($em, 'basic');
        $client->loginUser($manager);

        // 1. Request upgrade to Silver plan
        $client->request('GET', '/manager/billing/checkout/silver');
        $this->assertResponseRedirects(); // Redirects to Stripe Checkout

        // 2. Simulate webhook checkout.session.completed for Silver
        $payload = json_encode([
            'id' => 'evt_checkout_completed_' . uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'customer' => $org->getStripeCustomerId(),
                    'subscription' => 'sub_silver_' . uniqid(),
                    'amount_total' => 2900,
                    'metadata' => ['plan' => 'silver']
                ]
            ]
        ]);

        $client->request('POST', '/webhooks/stripe', [], [], [
            'HTTP_STRIPE_SIGNATURE' => 'mock_sig'
        ], $payload);

        $this->assertResponseIsSuccessful();

        $em->clear();
        $updatedOrg = $em->getRepository(Organization::class)->find($org->getId());
        $this->assertSame('silver', strtolower($updatedOrg->getPlan()->getName()));
        $this->assertNull($updatedOrg->getPendingPlan());

        // Verify Audit Log entry for Checkout Completed
        $log = $em->getRepository(\App\Entity\AuditLog::class)->findOneBy([
            'organization' => $updatedOrg,
            'action' => 'STRIPE_CHECKOUT_COMPLETED'
        ], ['id' => 'DESC']);
        $this->assertNotNull($log, 'AuditLog entry for STRIPE_CHECKOUT_COMPLETED must exist');
        $this->assertSame('silver', $log->getDetailsJson()['plan'] ?? null);
    }

    public function testCase2PaidToBasicPeriodEndCancellation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$org, $manager] = $this->createTestOrganization($em, 'silver');
        $client->loginUser($manager);

        // 1. Submit POST request to downgrade to basic
        $client->request('POST', '/manager/billing', [
            'plan' => 'basic'
        ]);

        $this->assertResponseRedirects('/manager/billing');
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert-info', 'Basic (Free) plan has been scheduled');

        $em->clear();
        $updatedOrg = $em->getRepository(Organization::class)->find($org->getId());
        // Verify org is STILL on Silver plan until period end
        $this->assertSame('silver', strtolower($updatedOrg->getPlan()->getName()));
        $this->assertNotNull($updatedOrg->getPendingPlan());
        $this->assertSame('basic', strtolower($updatedOrg->getPendingPlan()->getName()));
        $this->assertNotNull($updatedOrg->getPendingPlanEffectiveAt());

        // Verify Audit Log entry for Plan Downgrade Request Scheduled
        $logScheduled = $em->getRepository(\App\Entity\AuditLog::class)->findOneBy([
            'organization' => $updatedOrg,
            'action' => 'STRIPE_SUBSCRIPTION_CANCEL_SCHEDULED'
        ], ['id' => 'DESC']);
        if (!$logScheduled) {
            $logScheduled = $em->getRepository(\App\Entity\AuditLog::class)->findOneBy([
                'organization' => $updatedOrg,
                'action' => 'PLAN_DOWNGRADE_BASIC'
            ], ['id' => 'DESC']);
        }
        $this->assertNotNull($logScheduled, 'AuditLog entry for downgrade scheduling must exist');

        // 2. Simulate final day webhook customer.subscription.deleted
        $payload = json_encode([
            'id' => 'evt_sub_deleted_' . uniqid(),
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => $org->getStripeSubscriptionId(),
                    'customer' => $org->getStripeCustomerId()
                ]
            ]
        ]);

        $client->request('POST', '/webhooks/stripe', [], [], [
            'HTTP_STRIPE_SIGNATURE' => 'mock_sig'
        ], $payload);

        $this->assertResponseIsSuccessful();

        $em->clear();
        $finalOrg = $em->getRepository(Organization::class)->find($org->getId());
        $this->assertSame('basic', strtolower($finalOrg->getPlan()->getName()));
        $this->assertNull($finalOrg->getPendingPlan());
        $this->assertNull($finalOrg->getPendingPlanEffectiveAt());

        // Verify Audit Log entry for Subscription Cancelled
        $logCancelled = $em->getRepository(\App\Entity\AuditLog::class)->findOneBy([
            'organization' => $finalOrg,
            'action' => 'STRIPE_SUBSCRIPTION_CANCELLED'
        ], ['id' => 'DESC']);
        $this->assertNotNull($logCancelled, 'AuditLog entry for STRIPE_SUBSCRIPTION_CANCELLED must exist');
    }

    public function testCase3PaidLowerToPaidHigherImmediateUpgrade(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$org, $manager] = $this->createTestOrganization($em, 'silver');
        $client->loginUser($manager);

        // 1. Upgrade from Silver ($29) to Gold ($79)
        $client->request('POST', '/manager/billing', [
            'plan' => 'gold'
        ]);

        $this->assertResponseRedirects('/manager/billing/checkout/gold');

        // 2. Simulate webhook checkout.session.completed for Gold
        $payload = json_encode([
            'id' => 'evt_checkout_gold_' . uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'customer' => $org->getStripeCustomerId(),
                    'subscription' => 'sub_gold_' . uniqid(),
                    'amount_total' => 7900,
                    'metadata' => ['plan' => 'gold']
                ]
            ]
        ]);

        $client->request('POST', '/webhooks/stripe', [], [], [
            'HTTP_STRIPE_SIGNATURE' => 'mock_sig'
        ], $payload);

        $this->assertResponseIsSuccessful();

        $em->clear();
        $updatedOrg = $em->getRepository(Organization::class)->find($org->getId());
        $this->assertSame('gold', strtolower($updatedOrg->getPlan()->getName()));
        $this->assertNull($updatedOrg->getPendingPlan());

        // Verify Audit Log entry for Upgraded Checkout Completed
        $log = $em->getRepository(\App\Entity\AuditLog::class)->findOneBy([
            'organization' => $updatedOrg,
            'action' => 'STRIPE_CHECKOUT_COMPLETED'
        ], ['id' => 'DESC']);
        $this->assertNotNull($log, 'AuditLog entry for STRIPE_CHECKOUT_COMPLETED must exist');
        $this->assertSame('gold', $log->getDetailsJson()['plan'] ?? null);
    }

    public function testCase4PaidHigherToPaidLowerPeriodEndDowngrade(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$org, $manager] = $this->createTestOrganization($em, 'gold');
        $client->loginUser($manager);

        // 1. Submit request to downgrade from Gold ($79) to Silver ($29)
        $client->request('POST', '/manager/billing', [
            'plan' => 'silver'
        ]);

        $this->assertResponseRedirects('/manager/billing');
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert-info', 'scheduled');

        $em->clear();
        $updatedOrg = $em->getRepository(Organization::class)->find($org->getId());
        // Org stays on Gold until period end
        $this->assertSame('gold', strtolower($updatedOrg->getPlan()->getName()));
        $this->assertNotNull($updatedOrg->getPendingPlan());
        $this->assertSame('silver', strtolower($updatedOrg->getPendingPlan()->getName()));

        // Verify Audit Log entry for Scheduled Transition
        $logSched = $em->getRepository(\App\Entity\AuditLog::class)->findOneBy([
            'organization' => $updatedOrg,
            'action' => 'STRIPE_SUBSCRIPTION_CHANGE_SCHEDULED'
        ], ['id' => 'DESC']);
        $this->assertNotNull($logSched, 'AuditLog entry for STRIPE_SUBSCRIPTION_CHANGE_SCHEDULED must exist');
        $this->assertSame('silver', $logSched->getDetailsJson()['scheduled_plan'] ?? null);

        // 2. Simulate period end webhook invoice.payment_succeeded for Silver
        $payload = json_encode([
            'id' => 'evt_inv_silver_' . uniqid(),
            'type' => 'invoice.payment_succeeded',
            'data' => [
                'object' => [
                    'customer' => $org->getStripeCustomerId(),
                    'id' => 'in_silver_' . uniqid(),
                    'amount_paid' => 2900
                ]
            ]
        ]);

        $client->request('POST', '/webhooks/stripe', [], [], [
            'HTTP_STRIPE_SIGNATURE' => 'mock_sig'
        ], $payload);

        $this->assertResponseIsSuccessful();

        $em->clear();
        $finalOrg = $em->getRepository(Organization::class)->find($org->getId());
        $this->assertSame('silver', strtolower($finalOrg->getPlan()->getName()));
        $this->assertNull($finalOrg->getPendingPlan());
        $this->assertNull($finalOrg->getPendingPlanEffectiveAt());

        // Verify Audit Log entry for Payment Success & Applied Transition
        $logPaid = $em->getRepository(\App\Entity\AuditLog::class)->findOneBy([
            'organization' => $finalOrg,
            'action' => 'STRIPE_PAYMENT_SUCCESS'
        ], ['id' => 'DESC']);
        $this->assertNotNull($logPaid, 'AuditLog entry for STRIPE_PAYMENT_SUCCESS must exist');
        $this->assertSame('silver', $logPaid->getDetailsJson()['plan'] ?? null);
    }
}
