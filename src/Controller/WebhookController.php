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

                        // Log Checkout Completion Event
                        $log = new AuditLog();
                        $log->setOrganization($org);
                        $log->setAction('STRIPE_CHECKOUT_COMPLETED');
                        $log->setDetailsJson([
                            'stripe_customer_id' => $customerId,
                            'stripe_subscription_id' => $stripeSubId,
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
                        $payment = new PaymentHistory();
                        $payment->setOrganization($org);
                        $payment->setStripeInvoiceId($invoice->id ?? ('in_' . bin2hex(random_bytes(8))));
                        $payment->setAmount($amountPaid);
                        $payment->setStatus('paid');
                        $payment->setPaidAt(new \DateTime());
                        $payment->setPeriodStart(new \DateTime());
                        $payment->setPeriodEnd(new \DateTime('+1 month'));
                        $em->persist($payment);

                        $org->setIsSubscriptionActive(true);

                        // Log Subscription Payment/Charge Success Event
                        $log = new AuditLog();
                        $log->setOrganization($org);
                        $log->setAction('STRIPE_PAYMENT_SUCCESS');
                        $log->setDetailsJson([
                            'invoice_id' => $invoice->id ?? '',
                            'amount' => $amountPaid,
                        ]);
                        $log->setIpAddress($request->getClientIp());
                        $log->setUserAgent($request->headers->get('User-Agent'));
                        $em->persist($log);

                        $em->flush();

                        // Dispatch subscription renewal notice
                        $manager = $em->getRepository(Manager::class)->findOneBy(['organization' => $org, 'role' => 'ROLE_ORG_MANAGER']);
                        if ($manager) {
                            try {
                                $emailService->sendSubscriptionRenewalNotice($manager, $amountPaid, (new \DateTime('+1 month'))->format('M d, Y'));
                            } catch (\Exception $e) {
                                // Silently log or ignore mailer issues during webhooks to prevent blocking Stripe webhook returns
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
                        $org->setIsSubscriptionActive(false);

                        // Log Subscription Payment/Charge Failure Event
                        $log = new AuditLog();
                        $log->setOrganization($org);
                        $log->setAction('STRIPE_PAYMENT_FAILED');
                        $log->setDetailsJson([
                            'invoice_id' => $invoice->id ?? '',
                        ]);
                        $log->setIpAddress($request->getClientIp());
                        $log->setUserAgent($request->headers->get('User-Agent'));
                        $em->persist($log);

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
