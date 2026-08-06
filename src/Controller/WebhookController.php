<?php

namespace App\Controller;

use App\Service\EmailService;
use App\Service\Stripe\StripeWebhookDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WebhookController extends AbstractController
{
    private StripeWebhookDispatcher $webhookDispatcher;

    public function __construct(StripeWebhookDispatcher $webhookDispatcher)
    {
        $this->webhookDispatcher = $webhookDispatcher;
    }

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

        $this->webhookDispatcher->dispatch($event, $request, $em, $emailService, $isTestEnv, $isPlaceholderSecret);

        return new Response('Success', 200);
    }
}
