<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\PaymentHistory;
use App\Entity\Manager;
use App\Entity\AuditLog;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WebhookController extends AbstractController
{
    #[Route('/webhooks/stripe', name: 'app_stripe_webhook', methods: ['POST'])]
    public function handle(Request $request, EntityManagerInterface $em, EmailService $emailService): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature');
        $webhookSecret = $this->getParameter('stripe_webhook_secret');

        $isTestEnv = $this->getParameter('kernel.environment') === 'test';
        $isPlaceholderSecret = str_contains($webhookSecret, 'placeholder');

        if ($isTestEnv || $isPlaceholderSecret) {
            // Bypass signature verification in test environment or with placeholder secrets
            $data = json_decode($payload, true);
            if (!$data || !isset($data['type'])) {
                return new Response('Invalid mock payload', 400);
            }
            $event = (object)[
                'type' => $data['type'],
                'data' => (object)[
                    'object' => (object)$data['data']['object']
                ]
            ];
        } else {
            // Real production signature verification
            try {
                $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
            } catch (\UnexpectedValueException $e) {
                return new Response('Invalid payload', 400);
            } catch (\Stripe\Exception\SignatureVerificationException $e) {
                return new Response('Invalid signature', 400);
            }
        }

        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                $stripeSubId = $session->subscription ?? 'mock_sub_id';
                $customerId = $session->customer ?? null;

                if ($customerId) {
                    $org = $em->getRepository(Organization::class)->findOneBy(['stripeCustomerId' => $customerId]);
                    if ($org) {
                        $org->setStripeSubscriptionId($stripeSubId);
                        $org->setIsSubscriptionActive(true);

                        // Ensure any other previous active subscriptions in Stripe for this customer are canceled
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

                        // Try resolving purchased plan from metadata or line items
                        $purchasedPlanName = is_array($session->metadata) ? ($session->metadata['plan'] ?? null) : ($session->metadata->plan ?? null);
                        if ($purchasedPlanName) {
                            $purchasedPlan = $em->getRepository(\App\Entity\Plan::class)->findOneBy(['name' => $purchasedPlanName]);
                            if ($purchasedPlan) {
                                $org->setPlan($purchasedPlan);
                                if ($purchasedPlan->hasSecureKiosk()) {
                                    $org->setAccessMode('secure');
                                }
                            }
                        }

                        // Record PaymentHistory entry for new checkout completion
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

                        // Log Checkout Completion Event
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
                break;

            case 'invoice.payment_succeeded':
                $invoice = $event->data->object;
                $customerId = $invoice->customer ?? null;
                $amountPaid = number_format(($invoice->amount_paid ?? 0) / 100, 2, '.', '');
                
                if ($customerId) {
                    $org = $em->getRepository(Organization::class)->findOneBy(['stripeCustomerId' => $customerId]);
                    if ($org) {
                        // Apply pending lower paid plan if transition took effect at period end
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

                        // Log Subscription Payment/Charge Success Event
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

                        // Dispatch Email B: Plan Change / Renewal Notice
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
                break;

            case 'invoice.payment_failed':
                $invoice = $event->data->object;
                $customerId = $invoice->customer ?? null;

                if ($customerId) {
                    $org = $em->getRepository(Organization::class)->findOneBy(['stripeCustomerId' => $customerId]);
                    if ($org) {
                        // Requirement 6: Update org to basic plan on monthly payment failure
                        $basicPlan = $em->getRepository(\App\Entity\Plan::class)->findOneBy(['name' => 'basic']);
                        if ($basicPlan) {
                            $org->setPlan($basicPlan);
                            $org->clearOverrides();
                            $org->setAccessMode('open');
                        }
                        $org->setPendingPlan(null);
                        $org->setPendingPlanEffectiveAt(null);
                        $org->setIsSubscriptionActive(true);

                        // Log Subscription Payment Failure Event
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

                        // Log Plan Downgrade to Basic Event
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

                        // Dispatch subscription expiry warning notice
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
                break;

            case 'customer.subscription.deleted':
                $subscription = $event->data->object;
                $customerId = $subscription->customer ?? null;

                if ($customerId) {
                    $org = $em->getRepository(Organization::class)->findOneBy(['stripeCustomerId' => $customerId]);
                    if ($org) {
                        $basicPlan = $em->getRepository(\App\Entity\Plan::class)->findOneBy(['name' => 'basic']);
                        if ($basicPlan) {
                            $org->setPlan($basicPlan);
                            $org->clearOverrides();
                            $org->setAccessMode('open');
                        }
                        $org->setPendingPlan(null);
                        $org->setPendingPlanEffectiveAt(null);
                        $org->setStripeSubscriptionId(null);
                        $org->setIsSubscriptionActive(true);

                        // Log Subscription Cancelled Event
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

                        // Notify Manager of cancellation completion (Email B)
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
                break;

            case 'customer.subscription.updated':
                $subscription = $event->data->object;
                $customerId = $subscription->customer ?? null;
                $status = $subscription->status ?? '';

                if ($customerId) {
                    $org = $em->getRepository(Organization::class)->findOneBy(['stripeCustomerId' => $customerId]);
                    if ($org) {
                        if ($status === 'canceled' || $status === 'unpaid') {
                            $basicPlan = $em->getRepository(\App\Entity\Plan::class)->findOneBy(['name' => 'basic']);
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
                break;

            case 'invoice.upcoming':
                $invoice = $event->data->object;
                $customerId = $invoice->customer ?? null;
                $amountDue = number_format(($invoice->amount_due ?? 0) / 100, 2, '.', '');
                $dueDate = $invoice->next_payment_attempt ? (new \DateTime('@' . $invoice->next_payment_attempt))->format('M d, Y') : (new \DateTime('+7 days'))->format('M d, Y');
                
                if ($customerId) {
                    $org = $em->getRepository(Organization::class)->findOneBy(['stripeCustomerId' => $customerId]);
                    if ($org) {
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
                break;
        }

        return new Response('Success', 200);
    }
}
