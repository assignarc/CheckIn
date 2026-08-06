<?php

namespace App\Service\Stripe;

use Doctrine\ORM\EntityManagerInterface;
use App\Service\EmailService;
use Symfony\Component\HttpFoundation\Request;

interface StripeEventHandlerInterface
{
    public function supports(string $eventType): bool;

    public function handle(object $event, Request $request, EntityManagerInterface $em, EmailService $emailService, bool $isTestEnv, bool $isPlaceholderSecret): void;
}
