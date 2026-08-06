<?php

namespace App\Service\Stripe;

use App\Service\EmailService;
use App\Service\Stripe\Handler\CheckoutSessionCompletedHandler;
use App\Service\Stripe\Handler\CustomerSubscriptionDeletedHandler;
use App\Service\Stripe\Handler\CustomerSubscriptionUpdatedHandler;
use App\Service\Stripe\Handler\InvoicePaymentFailedHandler;
use App\Service\Stripe\Handler\InvoicePaymentSucceededHandler;
use App\Service\Stripe\Handler\InvoiceUpcomingHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class StripeWebhookDispatcher
{
    /** @var StripeEventHandlerInterface[] */
    private array $handlers;

    public function __construct()
    {
        $this->handlers = [
            new CheckoutSessionCompletedHandler(),
            new InvoicePaymentSucceededHandler(),
            new InvoicePaymentFailedHandler(),
            new CustomerSubscriptionDeletedHandler(),
            new CustomerSubscriptionUpdatedHandler(),
            new InvoiceUpcomingHandler(),
        ];
    }

    public function dispatch(object $event, Request $request, EntityManagerInterface $em, EmailService $emailService, bool $isTestEnv, bool $isPlaceholderSecret): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($event->type)) {
                $handler->handle($event, $request, $em, $emailService, $isTestEnv, $isPlaceholderSecret);
                return;
            }
        }
    }
}
