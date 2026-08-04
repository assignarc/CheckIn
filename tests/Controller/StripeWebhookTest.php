<?php

namespace App\Tests\Controller;

use App\Entity\Manager;
use App\Entity\Organization;
use App\Entity\PaymentHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StripeWebhookTest extends WebTestCase
{
    /**
     * Test Checkout redirection logic
     */
    public function testCheckoutSessionRedirection(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // Find manager and login
        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);

        // Request checkout session redirect for gold plan
        $client->request('GET', '/manager/billing/checkout/gold');
        
        // Assert that in test/mock environment it redirects directly to the success URL
        $this->assertResponseRedirects('/manager/billing?checkout_success=1');
    }

    /**
     * Test Stripe customer portal redirect
     */
    public function testCustomerPortalRedirection(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $manager = $em->getRepository(Manager::class)->findOneBy(['email' => 'manager@acme.com']);
        $client->loginUser($manager);

        // Request billing portal redirect
        $client->request('GET', '/manager/billing/portal');
        
        // In mock environment it should redirect back to billing page with success flash
        $this->assertResponseRedirects('/manager/billing');
    }

    /**
     * Test Stripe webhook handler for all supported webhook types
     */
    public function testStripeWebhookHandler(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $org = $em->getRepository(Organization::class)->findOneBy(['slug' => 'acme-corp']);
        $org->setStripeCustomerId('cus_test123');
        $org->setIsSubscriptionActive(true);
        $em->flush();

        // 1. Test invoice.payment_failed (should suspend subscription)
        $payloadFailed = json_encode([
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'customer' => 'cus_test123'
                ]
            ]
        ]);

        $client->request('POST', '/webhooks/stripe', [], [], [], $payloadFailed);
        $this->assertResponseIsSuccessful();

        $em->clear();
        $updatedOrg = $em->getRepository(Organization::class)->find($org->getId());
        $this->assertSame('basic', strtolower($updatedOrg->getPlan()->getName()));

        // 2. Test invoice.payment_succeeded (should restore/activate subscription and record payment)
        $payloadSucceeded = json_encode([
            'type' => 'invoice.payment_succeeded',
            'data' => [
                'object' => [
                    'customer' => 'cus_test123',
                    'amount_paid' => 999, // $9.99
                    'id' => 'in_test123'
                ]
            ]
        ]);

        $client->request('POST', '/webhooks/stripe', [], [], [], $payloadSucceeded);
        $this->assertResponseIsSuccessful();

        $em->clear();
        $updatedOrg = $em->getRepository(Organization::class)->find($org->getId());
        $this->assertTrue($updatedOrg->isSubscriptionActive());

        $payment = $em->getRepository(PaymentHistory::class)->findOneBy(['stripeInvoiceId' => 'in_test123']);
        $this->assertNotNull($payment);
        $this->assertEquals('9.99', $payment->getAmount());
        $this->assertEquals('paid', $payment->getStatus());

        // 3. Test invoice.upcoming (should dispatch email notice and keep active status)
        $payloadUpcoming = json_encode([
            'type' => 'invoice.upcoming',
            'data' => [
                'object' => [
                    'customer' => 'cus_test123',
                    'amount_due' => 2900, // $29.00
                    'next_payment_attempt' => time() + 7 * 24 * 3600
                ]
            ]
        ]);

        $client->request('POST', '/webhooks/stripe', [], [], [], $payloadUpcoming);
        $this->assertResponseIsSuccessful();
    }
}
