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
            // Simulate Stripe Customer ID creation
            if (!$org->getStripeCustomerId()) {
                $org->setStripeCustomerId('cus_' . bin2hex(random_bytes(8)));
                $org->setStripeSubscriptionId('sub_' . bin2hex(random_bytes(8)));
                $org->setPlan($plan);
                $org->setIsSubscriptionActive(true);
                $em->flush();
            }
            return $this->redirect($successUrl);
        }

        // Production Stripe initialization
        Stripe::setApiKey($stripeSecret);

        // Map plans to your product/price IDs in Stripe Dashboard
        $stripePriceId = match($plan->getName()) {
            'silver' => 'price_silver_id_here',
            'gold' => 'price_gold_id_here',
            'platinum' => 'price_platinum_id_here',
            default => null
        };

        if (!$org->getStripeCustomerId()) {
            $customer = \Stripe\Customer::create([
                'email' => $manager->getEmail(),
                'name' => $org->getName(),
                'metadata' => ['org_id' => $org->getId()]
            ]);
            $org->setStripeCustomerId($customer->id);
            $em->flush();
        }

        $session = CheckoutSession::create([
            'customer' => $org->getStripeCustomerId(),
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $stripePriceId,
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        return $this->redirect($session->url);
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
