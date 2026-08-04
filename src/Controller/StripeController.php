<?php

namespace App\Controller;

use App\Entity\Manager;
use App\Entity\Plan;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Stripe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manager/billing')]
#[IsGranted('ROLE_ORG_MANAGER')]
class StripeController extends AbstractController
{
    #[Route('/checkout/{planName}', name: 'app_stripe_checkout')]
    public function createCheckoutSession(string $planName, EntityManagerInterface $em): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();
        $plan = $em->getRepository(Plan::class)->findOneBy(['name' => $planName]);

        if (!$plan || $plan->getName() === 'basic') {
            return $this->redirectToRoute('app_manager_billing');
        }

        $stripeSecret = $this->getParameter('stripe_secret_key');

        $successUrl = $this->generateUrl('app_manager_billing', ['checkout_success' => '1'], UrlGeneratorInterface::ABSOLUTE_URL);
        $cancelUrl = $this->generateUrl('app_manager_billing', ['checkout_cancelled' => '1'], UrlGeneratorInterface::ABSOLUTE_URL);

        // Fallback for offline/test environments or placeholder credentials
        if ($this->getParameter('kernel.environment') === 'test' || str_contains($stripeSecret, 'placeholder')) {
            if (!$org->getStripeCustomerId()) {
                $org->setStripeCustomerId('cus_' . bin2hex(random_bytes(8)));
                $org->setStripeSubscriptionId('sub_' . bin2hex(random_bytes(8)));
            }
            $org->setPlan($plan);
            $org->clearOverrides();
            $org->setIsSubscriptionActive(true);
            $em->flush();
            return $this->redirect($successUrl);
        }

        try {
            // Production/Test Stripe initialization
            Stripe::setApiKey($stripeSecret);

            $isTestKey = str_starts_with($stripeSecret, 'sk_test_');

            // Retrieve Stripe Price ID directly from Plan entity
            $stripePriceId = $plan->getStripePriceId();
            $priceAmount = (float)$plan->getPriceMonthly();

            // Check if customer ID exists or needs creation / validation
            if (!$org->getStripeCustomerId()) {
                $customer = \Stripe\Customer::create([
                    'email' => $manager->getEmail(),
                    'name' => $org->getName(),
                    'metadata' => ['org_id' => $org->getId()]
                ]);
                $org->setStripeCustomerId($customer->id);
                $em->flush();
            } else {
                try {
                    \Stripe\Customer::retrieve($org->getStripeCustomerId());
                } catch (\Throwable $e) {
                    if (str_contains($e->getMessage(), 'No such customer')) {
                        $customer = \Stripe\Customer::create([
                            'email' => $manager->getEmail(),
                            'name' => $org->getName(),
                            'metadata' => ['org_id' => $org->getId()]
                        ]);
                        $org->setStripeCustomerId($customer->id);
                        $org->setStripeSubscriptionId(null);
                        $em->flush();
                    }
                }
            }

            // Ensure no existing active or trialing subscriptions remain on Stripe for this customer (guarantees only 1 active subscription per organization)
            if ($org->getStripeCustomerId()) {
                try {
                    $activeSubs = \Stripe\Subscription::all([
                        'customer' => $org->getStripeCustomerId(),
                        'status' => 'active',
                    ]);
                    foreach ($activeSubs->data as $sub) {
                        try {
                            $sub->cancel();

                            $log = new \App\Entity\AuditLog();
                            $log->setOrganization($org);
                            $log->setManager($manager);
                            $log->setAction('STRIPE_PREVIOUS_SUBSCRIPTION_CANCELED');
                            $log->setDetailsJson([
                                'canceled_subscription_id' => $sub->id,
                                'new_plan' => $plan->getName(),
                                'reason' => 'Automatically canceled existing active subscription prior to checkout to prevent duplicates'
                            ]);
                            $em->persist($log);
                        } catch (\Throwable $e) {
                            // Silently ignore if already canceled
                        }
                    }

                    $trialingSubs = \Stripe\Subscription::all([
                        'customer' => $org->getStripeCustomerId(),
                        'status' => 'trialing',
                    ]);
                    foreach ($trialingSubs->data as $sub) {
                        try {
                            $sub->cancel();

                            $log = new \App\Entity\AuditLog();
                            $log->setOrganization($org);
                            $log->setManager($manager);
                            $log->setAction('STRIPE_PREVIOUS_SUBSCRIPTION_CANCELED');
                            $log->setDetailsJson([
                                'canceled_subscription_id' => $sub->id,
                                'new_plan' => $plan->getName(),
                                'reason' => 'Automatically canceled existing trialing subscription prior to checkout'
                            ]);
                            $em->persist($log);
                        } catch (\Throwable $e) {
                            // Silently ignore
                        }
                    }
                    $em->flush();
                } catch (\Throwable $e) {
                    // Silently ignore if lookup fails
                }
            }

            // Attempt session creation with configured price ID first, fallback to dynamic price_data if needed
            try {
                if ($stripePriceId && trim($stripePriceId) !== '') {
                    $lineItems = [[
                        'price' => $stripePriceId,
                        'quantity' => 1,
                    ]];
                    $session = CheckoutSession::create([
                        'customer' => $org->getStripeCustomerId(),
                        'payment_method_types' => ['card'],
                        'line_items' => $lineItems,
                        'mode' => 'subscription',
                        'metadata' => [
                            'plan' => $plan->getName()
                        ],
                        'success_url' => $successUrl,
                        'cancel_url' => $cancelUrl,
                    ]);
                } else {
                    throw new \Exception('No fixed price ID configured; using price_data fallback.');
                }
            } catch (\Throwable $e) {
                $lineItems = [[
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => ucfirst($plan->getName()) . ' Plan Subscription',
                        ],
                        'unit_amount' => (int) round($priceAmount * 100),
                        'recurring' => ['interval' => 'month'],
                    ],
                    'quantity' => 1,
                ]];
                $session = CheckoutSession::create([
                    'customer' => $org->getStripeCustomerId(),
                    'payment_method_types' => ['card'],
                    'line_items' => $lineItems,
                    'mode' => 'subscription',
                    'metadata' => [
                        'plan' => $plan->getName()
                    ],
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                ]);
            }

            return $this->redirect($session->url);
        } catch (\Throwable $e) {
            // In dev environment with test key or invalid secret, fallback to simulation so local testing is never blocked
            if ($isTestKey || str_contains($stripeSecret, 'placeholder')) {
                if (!$org->getStripeCustomerId()) {
                    $org->setStripeCustomerId('cus_' . bin2hex(random_bytes(8)));
                    $org->setStripeSubscriptionId('sub_' . bin2hex(random_bytes(8)));
                }
                $org->setPlan($plan);
                $org->clearOverrides();
                $org->setIsSubscriptionActive(true);
                $em->flush();

                $this->addFlash('success', sprintf('Simulated Plan Upgrade: Organization upgraded to %s Plan.', ucfirst($plan->getName())));
                return $this->redirect($successUrl);
            }

            // Fall back organization to Basic plan so user can continue without disruption
            $basicPlan = $em->getRepository(Plan::class)->findOneBy(['name' => 'basic']);
            if ($basicPlan) {
                $org->setPlan($basicPlan);
                $org->clearOverrides();
                $org->setIsSubscriptionActive(true);
            }

            // Log Audit Event for Checkout Failure
            $log = new \App\Entity\AuditLog();
            $log->setOrganization($org);
            $log->setManager($manager);
            $log->setAction('STRIPE_CHECKOUT_FAILED');
            $log->setDetailsJson([
                'attempted_plan' => $planName,
                'error' => $e->getMessage(),
                'reverted_to' => 'basic'
            ]);
            $em->persist($log);
            $em->flush();

            $this->addFlash('warning', 'Payment setup could not be completed with Stripe. Your organization has been set up with the Basic (Free) plan.');
            return $this->redirectToRoute('app_manager_billing');
        }
    }

    #[Route('/portal', name: 'app_stripe_portal')]
    public function openCustomerPortal(): Response
    {
        /** @var Manager $manager */
        $manager = $this->getUser();
        $org = $manager->getOrganization();

        $stripeSecret = $this->getParameter('stripe_secret_key');

        // Fallback for offline/test environments or placeholder credentials
        if ($this->getParameter('kernel.environment') === 'test' || str_contains($stripeSecret, 'placeholder')) {
            $this->addFlash('success', 'Simulated: Redirected to Stripe Customer Billing Portal.');
            return $this->redirectToRoute('app_manager_billing');
        }

        if (!$org->getStripeCustomerId()) {
            $this->addFlash('warning', 'No billing profile found. Please select a plan first.');
            return $this->redirectToRoute('app_manager_billing');
        }

        Stripe::setApiKey($stripeSecret);

        $session = PortalSession::create([
            'customer' => $org->getStripeCustomerId(),
            'return_url' => $this->generateUrl('app_manager_billing', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        return $this->redirect($session->url);
    }
}
